<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\LlmClient;
use App\Domain\Assistant\Providers;
use App\Domain\Installer\CronInstaller;
use App\Domain\Installer\SystemValidator;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\Notifier;
use App\Domain\System\SettingsValidation;
use App\Domain\System\SystemSettings;
use App\Domain\System\Updater;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private SystemSettings $settings) {}

    public function show(Request $request, ManifestLoader $manifests): Response
    {
        // Everyone: their own MCP tokens. Members see nothing else beyond account and appearance.
        $mcp = ['url' => url('/api/mcp'), 'tokens' => $this->tokens($request->user())];
        if (! $request->user()->isAdmin()) {
            return Inertia::render('Settings', ['mcp' => $mcp]);
        }

        $mail = $this->settings->mail();
        $ai = (array) $this->settings->get('ai', []);
        $catalog = $manifests->catalogMap();

        return Inertia::render('Settings', [
            'mcp' => $mcp,
            // Secrets never leave the server: only whether one is stored.
            'mail' => $mail ? Arr::except($mail, ['password']) + ['has_password' => ! empty($mail['password'])] : null,
            'notifications' => $this->settings->notifications(),
            'timezone' => $this->settings->timezone(),
            'team' => ['members' => TeamController::members(), 'permissions' => User::PERMISSIONS],
            'cron' => app(SystemValidator::class)->cronCommand(),
            'updates' => [
                'version' => app(Updater::class)->current(),
                'history' => (array) $this->settings->get('update_history', []),
                'runner' => url('update-runner.php'),
            ],
            'ai' => Arr::except($ai, ['api_key']) + ['has_key' => ! empty($ai['api_key'])],
            'providers' => collect(Providers::ALL)->map(fn ($p, $key) => Arr::only($p, ['name', 'model', 'models', 'base_url', 'base_urls', 'key_url']) + [
                'key' => $key,
                'icon' => $catalog[$p['icon_key']]['icon'] ?? 'plug',
            ])->values(),
        ]);
    }

    private function tokens(User $user): array
    {
        return ApiToken::query()->where('user_id', $user->id)->latest()->get()
            ->map(fn (ApiToken $t) => ['id' => $t->id, 'name' => $t->name, 'created_at' => $t->created_at?->toIso8601String(), 'last_used_at' => $t->last_used_at?->toIso8601String()])
            ->all();
    }

    /** The plain token is returned once; only its hash is stored. */
    public function createToken(Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:100']])['name'];
        [, $plain] = ApiToken::issue($request->user(), $name);

        return response()->json(['token' => $plain, 'tokens' => $this->tokens($request->user())]);
    }

    public function revokeToken(Request $request, int $token): JsonResponse
    {
        ApiToken::query()->where('user_id', $request->user()->id)->whereKey($token)->delete();

        return response()->json(['tokens' => $this->tokens($request->user())]);
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => ['required', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(10)],
        ]);

        $user->fill(array_filter([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'] ?? null,
        ]))->save();

        return back()->with('success', 'Account updated.');
    }

    public function updateAppearance(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate(['theme' => ['required', 'in:light,dark,system']]));

        return back();
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $this->settings->set('timezone', $request->validate(['timezone' => ['required', 'timezone:all']])['timezone']);

        return back()->with('success', 'Timezone saved. Schedules now run in '.$this->settings->timezone().'.');
    }

    public function autoCron(CronInstaller $cron): JsonResponse
    {
        return response()->json($cron->install());
    }

    public function updateMail(Request $request): RedirectResponse
    {
        [$mail, $notify] = SettingsValidation::mail($request, $this->settings->mail());
        $this->settings->set('mail', $mail);
        if ($notify !== null) {
            $this->settings->set('notifications', array_merge($this->settings->notifications(), ['recipients' => [$notify]]));
        }

        return back()->with('success', 'Email settings saved.');
    }

    public function testMail(Request $request, Notifier $notifier): JsonResponse
    {
        [$mail] = SettingsValidation::mail($request, $this->settings->mail());
        $to = $request->input('notify_email') ?: $request->user()->email;
        $error = $notifier->sendTest($mail, $to);

        return response()->json(['ok' => $error === null, 'message' => $error ?? "Test email sent to {$to}."], $error ? 422 : 200);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'on_failure' => ['required', 'boolean'],
            'on_scheduler_stall' => ['required', 'boolean'],
            'recipients' => ['array', 'max:10'],
            'recipients.*' => ['email'],
            'throttle_minutes' => ['required', 'integer', 'between:1,1440'],
        ]);
        $this->settings->set('notifications', $data);

        return back()->with('success', 'Alert settings saved.');
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $this->settings->set('ai', $this->validatedAi($request));

        return back()->with('success', 'AI assistant settings saved.');
    }

    /** Cheapest possible round trip: a few tokens to prove the key, model and endpoint work. */
    public function testAi(Request $request, LlmClient $llm): JsonResponse
    {
        try {
            $reply = $llm->ping($this->validatedAi($request));

            return response()->json(['ok' => true, 'message' => 'Connected. The model replied: "'.mb_strimwidth(trim($reply), 0, 60, '…').'"']);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => mb_strimwidth($e->getMessage(), 0, 300, '…')], 422);
        }
    }

    private function validatedAi(Request $request): array
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Providers::ALL))],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'effort' => ['nullable', 'in:low,medium,high'],
        ]);
        $existing = (array) $this->settings->get('ai', []);

        // Keep the stored key when the field is left blank and the provider didn't change.
        if (($data['api_key'] ?? '') === '' && ($existing['provider'] ?? null) === $data['provider']) {
            $data['api_key'] = $existing['api_key'] ?? null;
        }
        if (empty($data['api_key'])) {
            throw ValidationException::withMessages(['api_key' => 'Enter the API key for this provider.']);
        }
        $data['base_url'] = $data['base_url'] ?: Providers::get($data['provider'])['base_url'];
        $data['effort'] ??= 'medium';

        return $data;
    }
}
