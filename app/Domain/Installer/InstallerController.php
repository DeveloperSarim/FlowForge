<?php

namespace App\Domain\Installer;

use App\Domain\System\Notifier;
use App\Domain\System\SettingsValidation;
use App\Domain\System\SystemSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use PDO;
use Throwable;

/**
 * Stateless install wizard: progress is derived from disk/DB state, so a reload or a
 * half-finished install always resumes at the right step.
 */
class InstallerController extends Controller
{
    public function __construct(private SystemValidator $validator, private EnvManager $env) {}

    public function show(): Response
    {
        $database = $this->databaseReady();

        return Inertia::render('Install/Wizard', [
            'checks' => $this->validator->checks(),
            'state' => [
                'database' => $database,
                'admin' => $database && User::query()->exists(),
                'mail' => $database && rescue(fn () => app(SystemSettings::class)->mail() !== null || app(SystemSettings::class)->get('mail_skipped'), false, false),
            ],
            'adminEmail' => $database ? rescue(fn () => User::query()->value('email'), null, false) : null,
            'defaults' => [
                'host' => config('database.connections.mysql.host') ?: 'localhost',
                'port' => (int) (config('database.connections.mysql.port') ?: 3306),
                'database' => config('database.connections.mysql.database'),
                'username' => config('database.connections.mysql.username'),
            ],
            'cron' => [
                'command' => $this->validator->cronCommand(),
                'php' => $this->validator->phpCliBinary(),
                'artisan' => base_path('artisan'),
                'version' => PHP_MAJOR_VERSION.PHP_MINOR_VERSION, // e.g. 82, for the /opt/alt/php82 style paths
            ],
        ]);
    }

    public function testDatabase(Request $request): JsonResponse
    {
        try {
            $version = $this->connect($this->validateDatabase($request))->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            return response()->json(['ok' => true, 'message' => "Connected — server version {$version}."]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->cleanError($e)], 422);
        }
    }

    public function database(Request $request): RedirectResponse
    {
        abort_unless($this->validator->passes(), 409, 'Resolve the system requirements first.');
        $data = $this->validateDatabase($request);

        try {
            $this->connect($data);
        } catch (Throwable $e) {
            return back()->withErrors(['host' => 'Connection failed: '.$this->cleanError($e)]);
        }

        try {
            $this->env->set([
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $data['host'],
                'DB_PORT' => $data['port'],
                'DB_DATABASE' => $data['database'],
                'DB_USERNAME' => $data['username'],
                'DB_PASSWORD' => $data['password'] ?? '',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
                'APP_URL' => $request->root(),
            ]);

            // The new .env applies from the next request; run this request against the new connection too.
            config(['cache.default' => 'database', 'queue.default' => 'database']);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['setup' => 'Setup failed: '.$this->cleanError($e)]);
        }

        return redirect('/install');
    }

    public function admin(Request $request): RedirectResponse
    {
        abort_unless($this->databaseReady(), 409, 'Configure the database first.');
        abort_if(User::query()->exists(), 403, 'An administrator already exists.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        User::query()->create(Arr::except($data, 'timezone'));
        if (! empty($data['timezone'])) {
            app(SystemSettings::class)->set('timezone', $data['timezone']);
        }

        return redirect('/install');
    }

    public function mail(Request $request, SystemSettings $settings): RedirectResponse
    {
        abort_unless($this->databaseReady() && User::query()->exists(), 409, 'Create the administrator first.');

        [$mail, $notify] = SettingsValidation::mail($request);
        $settings->set('mail', $mail);
        $settings->set('notifications', array_merge($settings->notifications(), ['recipients' => array_filter([$notify])]));

        return redirect('/install');
    }

    public function testMail(Request $request, Notifier $notifier): JsonResponse
    {
        abort_unless($this->databaseReady(), 409);
        [$mail, $notify] = SettingsValidation::mail($request, requirePassword: false);
        $to = $notify ?: User::query()->value('email');
        $error = $notifier->sendTest($mail, $to);

        return response()->json(['ok' => $error === null, 'message' => $error ?? "Test email sent to {$to}. Check the inbox (and spam folder)."], $error ? 422 : 200);
    }

    public function skipMail(SystemSettings $settings): RedirectResponse
    {
        abort_unless($this->databaseReady() && User::query()->exists(), 409);
        $settings->set('mail_skipped', true);

        return redirect('/install');
    }

    public function heartbeat(): JsonResponse
    {
        return response()->json(['last_run' => $this->lastCronRun(), 'verified' => $this->cronVerified()]);
    }

    private function lastCronRun(): ?string
    {
        return rescue(fn () => $this->databaseReady() ? Cache::store('database')->get('flowforge:heartbeat') : null, null, false);
    }

    /** The cron entry is proven once schedule:run has written a heartbeat in the last 3 minutes. */
    private function cronVerified(): bool
    {
        $last = $this->lastCronRun();

        return $last !== null && Carbon::parse($last)->gt(now()->subMinutes(3));
    }

    public function finish(): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($this->databaseReady() && User::query()->exists(), 409, 'Create the administrator first.');
        abort_unless($this->cronVerified(), 409, 'The cron job has not run yet. Add it in your hosting panel and wait for the green check.');

        // The APP_KEY is already unique (bootstrap/app.php generates it on first boot). It is not rotated
        // here: SMTP settings saved in the previous step are encrypted with it.
        $this->env->set(['APP_ENV' => 'production', 'APP_DEBUG' => false]);

        if (! touch(EnsureInstalled::flagPath())) {
            abort(500, 'Could not write '.EnsureInstalled::flagPath());
        }

        return Inertia::location(url('/login'));
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: ?string}
     */
    private function validateDatabase(Request $request): array
    {
        return $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function connect(array $data): Connection
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => array_merge(config('database.connections.mysql'), [
                'host' => $data['host'],
                'port' => $data['port'],
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'] ?? '',
                'options' => [PDO::ATTR_TIMEOUT => 5],
            ]),
        ]);
        DB::purge('mysql');

        $connection = DB::connection('mysql');
        $connection->getPdo();

        return $connection;
    }

    private function databaseReady(): bool
    {
        if (! config('database.connections.mysql.database')) {
            return false;
        }

        return rescue(fn () => Schema::hasTable('workflow_executions'), false, false);
    }

    private function cleanError(Throwable $e): string
    {
        // PDO messages can be multi-line with SQL context; the first line is what matters.
        return strtok($e->getMessage(), "\n") ?: class_basename($e);
    }
}
