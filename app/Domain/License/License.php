<?php

namespace App\Domain\License;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The CodeCanyon license. Activation sends the purchase code and this domain to the license server,
 * which checks the sale with Envato, binds the code to one production domain and returns a token signed
 * with its private key. The app only trusts tokens that verify against LicenseKey::PUBLIC and name the
 * domain it is running on, and re-checks with the server daily (refunds and moved licenses are revoked).
 */
class License
{
    public const PRODUCT = 'flowforge';

    /** Development hosts: don't use up the license's one production domain. */
    public const DEV_HOST = '/^(localhost|127\.0\.0\.1|\[::1\]|::1|.+\.(test|local|localhost))$/';

    private const OFFLINE_GRACE_DAYS = 14;

    private ?array $state = null;

    public function __construct(private string $publicKey = LicenseKey::PUBLIC) {}

    public function path(): string
    {
        return config('flowforge.license.path') ?: storage_path('app/license.json');
    }

    /** The domain this copy runs on: the request's host (in cron/queue Laravel builds the request from APP_URL). */
    public static function host(?string $host = null): string
    {
        $host = strtolower(trim((string) ($host ?? request()->getHost())));

        return (string) preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $host));
    }

    public function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $raw = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return $this->state = is_array($raw) ? $raw : [];
    }

    /** The verified token payload, or null when there is none or it was tampered with. */
    public function payload(): ?array
    {
        $state = $this->state();
        if (empty($state['token']) || empty($state['signature'])) {
            return null;
        }
        $json = base64_decode((string) $state['token'], true);
        $signature = base64_decode((string) $state['signature'], true);
        if ($json === false || $signature === false || openssl_verify($json, $signature, $this->publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $payload = json_decode($json, true);

        return is_array($payload) && ($payload['product'] ?? null) === self::PRODUCT ? $payload : null;
    }

    /** @return string|null why the license doesn't allow running here, or null when it does */
    public function problem(?string $host = null): ?string
    {
        $payload = $this->payload();
        if (! $this->state()) {
            return 'FlowForge is not activated. Enter your CodeCanyon purchase code.';
        }
        if (! $payload) {
            return 'The license file is invalid. Activate again with your purchase code.';
        }
        if (($payload['domain'] ?? null) !== self::host($host)) {
            return 'This license is activated for '.($payload['domain'] ?? 'another domain').'. Deactivate it there (Settings → License) or ask support to move it, then activate here.';
        }
        if (($this->state()['status'] ?? 'active') === 'revoked') {
            return 'This license was revoked'.(! empty($this->state()['reason']) ? ': '.$this->state()['reason'] : '').'. Contact support@sarimtools.com.';
        }
        $lastOk = (int) ($this->state()['checked_at'] ?? $payload['issued_at'] ?? 0);
        if ($lastOk && time() - $lastOk > self::OFFLINE_GRACE_DAYS * 86400 && ($this->state()['check_failed_at'] ?? 0)) {
            return 'The license server could not be reached for '.self::OFFLINE_GRACE_DAYS.' days. Check that this server can reach '.config('flowforge.license.server').'.';
        }

        return null;
    }

    public function valid(?string $host = null): bool
    {
        return $this->problem($host) === null;
    }

    public function isDemo(): bool
    {
        return $this->valid() && ! empty($this->payload()['demo']);
    }

    /** Ask the license server for a token for this domain. Throws with a message for the user. */
    public function activate(string $purchaseCode, ?string $host = null): array
    {
        $code = trim($purchaseCode);
        $host = self::host($host);
        $response = $this->call('activate', ['purchase_code' => $code, 'domain' => $host, 'product' => self::PRODUCT, 'version' => trim((string) @file_get_contents(base_path('VERSION')))]);

        $this->store(['token' => $response['token'], 'signature' => $response['signature'], 'code' => Crypt::encryptString($code),
            'code_hint' => Str::mask($code, '*', 4, -4), 'activated_at' => time(), 'checked_at' => time(), 'status' => 'active']);
        if ($this->problem($host)) {
            $problem = $this->problem($host);
            @unlink($this->path());
            $this->state = null;
            throw new RuntimeException('The license server answered with a token this copy can\'t verify. '.$problem);
        }

        return $this->payload();
    }

    /** Free this domain so the purchase code can be activated elsewhere. */
    public function deactivate(): void
    {
        if ($this->state()) {
            rescue(fn () => $this->call('deactivate', ['token' => $this->state()['token'], 'signature' => $this->state()['signature']]), null, false);
        }
        @unlink($this->path());
        $this->state = null;
    }

    /** Daily check with the server: picks up revocations and refreshed tokens; tolerates the server being down. */
    public function refresh(): void
    {
        if (! $this->payload()) {
            return;
        }
        try {
            $response = $this->call('verify', ['token' => $this->state()['token'], 'signature' => $this->state()['signature']]);
            $this->store(array_merge($this->state(), array_filter([
                'token' => $response['token'] ?? null, 'signature' => $response['signature'] ?? null,
            ]), ['status' => $response['status'] ?? 'active', 'reason' => $response['reason'] ?? null, 'checked_at' => time(), 'check_failed_at' => null]));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'revoked')) {
                $this->store(array_merge($this->state(), ['status' => 'revoked', 'reason' => $e->getMessage(), 'checked_at' => time()]));
            } else {
                $this->store(array_merge($this->state(), ['check_failed_at' => time()]));
            }
        }
    }

    public function summary(): array
    {
        $payload = $this->payload() ?? [];

        return array_filter([
            'valid' => $this->valid(),
            'problem' => $this->problem(),
            'domain' => $payload['domain'] ?? null,
            'type' => $payload['type'] ?? null,
            'buyer' => $payload['buyer'] ?? null,
            'supported_until' => $payload['supported_until'] ?? null,
            'demo' => ! empty($payload['demo']),
            'code' => $this->state()['code_hint'] ?? null,
            'activated_at' => isset($this->state()['activated_at']) ? date(DATE_ATOM, (int) $this->state()['activated_at']) : null,
            'checked_at' => isset($this->state()['checked_at']) ? date(DATE_ATOM, (int) $this->state()['checked_at']) : null,
        ], fn ($v) => $v !== null);
    }

    private function call(string $action, array $body): array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->withUserAgent('FlowForge/'.trim((string) @file_get_contents(base_path('VERSION'))))
                ->post(rtrim((string) config('flowforge.license.server'), '/')."/api/v1/{$action}", $body);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach the license server ('.config('flowforge.license.server').'). Check this server\'s internet access and try again.', previous: $e);
        }
        $data = (array) $response->json();
        if (! $response->successful()) {
            throw new RuntimeException((string) ($data['message'] ?? "The license server answered HTTP {$response->status()}."));
        }

        return $data;
    }

    private function store(array $state): void
    {
        if (! is_dir(dirname($this->path()))) {
            mkdir(dirname($this->path()), 0755, true);
        }
        file_put_contents($this->path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $this->state = $state;
    }
}
