<?php

namespace App\Domain\Demo;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\System\SystemSettings;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The public live demo, switched on with FLOWFORGE_DEMO=true in .env. Visitors sign in with the demo account shown
 * on the login page and can explore, build and edit workflows and use the AI builder; deleting, credentials, running
 * or switching on workflows and every setting are blocked. Workflows reset to a saved starting point hourly.
 */
class DemoMode
{
    public function __construct(private SystemSettings $settings) {}

    public function active(): bool
    {
        return (bool) config('flowforge.demo');
    }

    /** @return array{email: string, password: string} shown on the login page */
    public function credentials(): array
    {
        $saved = (array) $this->settings->get('demo', []);

        return ['email' => $saved['email'] ?? 'demo@'.preg_replace('/^www\./', '', (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com')), 'password' => $saved['password'] ?? 'demo12345'];
    }

    public function isDemoUser(?User $user): bool
    {
        return $user !== null && $this->active() && strcasecmp($user->email, $this->credentials()['email']) === 0;
    }

    /** Creates (or resets) the visitors' account: an admin, so every page can be looked at. */
    public function ensureUser(): User
    {
        $credentials = $this->credentials();

        return tap(User::query()->firstOrNew(['email' => $credentials['email']]), function (User $user) use ($credentials) {
            $user->forceFill(['name' => 'Demo visitor', 'password' => $credentials['password'], 'role' => 'admin', 'permissions' => null,
                'theme' => 'light', 'invite_token' => null, 'remember_token' => Str::random(60)])->save();
        });
    }

    public function saveCredentials(string $email, string $password): void
    {
        $previous = $this->credentials()['email'];
        $this->settings->set('demo', ['email' => strtolower($email), 'password' => $password]);
        if (strcasecmp($previous, $email) !== 0) {
            User::query()->where('email', $previous)->where('role', 'admin')->where('name', 'Demo visitor')->delete();
        }
        $this->ensureUser();
    }

    private function snapshotPath(): string
    {
        return storage_path('app/demo-snapshot.json');
    }

    /** Remembers the current workflows as the state every reset returns to. */
    public function snapshot(): int
    {
        $rows = DB::table('workflows')->get()->map(fn ($row) => (array) $row)->all();
        file_put_contents($this->snapshotPath(), json_encode(['saved_at' => now()->toIso8601String(), 'workflows' => $rows]));

        return count($rows);
    }

    public function snapshotInfo(): ?array
    {
        $data = is_file($this->snapshotPath()) ? json_decode((string) file_get_contents($this->snapshotPath()), true) : null;

        return $data ? ['saved_at' => $data['saved_at'], 'workflows' => count($data['workflows'])] : null;
    }

    /** Back to the starting point: visitors' workflows, chats and the demo account's changes are undone. */
    public function reset(): void
    {
        $user = $this->ensureUser();
        AiConversation::query()->where('user_id', $user->id)->delete();
        ApiToken::query()->where('user_id', $user->id)->delete();

        $data = is_file($this->snapshotPath()) ? json_decode((string) file_get_contents($this->snapshotPath()), true) : null;
        if (! is_array($data['workflows'] ?? null)) {
            return;
        }
        DB::transaction(function () use ($data) {
            $keep = array_column($data['workflows'], 'id');
            DB::table('workflows')->whereNotIn('id', $keep ?: [0])->delete(); // executions cascade
            foreach ($data['workflows'] as $row) {
                DB::table('workflows')->updateOrInsert(['id' => $row['id']], $row);
            }
        });
    }
}
