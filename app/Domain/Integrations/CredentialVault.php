<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\Credential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Knows which secret fields each auth type needs, and how to attach a stored credential to an HTTP request
 * (including OAuth2 token refresh and Google service-account JWT exchange).
 */
class CredentialVault
{
    public const FIELDS = [
        'none' => [],
        'api_key' => [['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true]],
        'bearer' => [['key' => 'token', 'label' => 'Access Token', 'type' => 'password', 'required' => true]],
        'basic' => [
            ['key' => 'username', 'label' => 'Username', 'type' => 'string', 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
        ],
        'oauth2' => [
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'string', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'oauth2_client_credentials' => [
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'string', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'google_service_account' => [
            ['key' => 'service_account_json', 'label' => 'Service Account JSON key', 'type' => 'code', 'required' => true,
                'help' => 'Paste the full JSON key file. Share the spreadsheet with the service account email.'],
        ],
        'smtp' => [
            ['key' => 'host', 'label' => 'SMTP Host', 'type' => 'string', 'required' => true, 'placeholder' => 'smtp.example.com'],
            ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'default' => 587],
            ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'default' => 'tls', 'options' => [
                ['value' => 'tls', 'label' => 'STARTTLS (587)'], ['value' => 'ssl', 'label' => 'SSL/TLS (465)'], ['value' => 'none', 'label' => 'None'],
            ]],
            ['key' => 'username', 'label' => 'Username', 'type' => 'string'],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
            ['key' => 'from_address', 'label' => 'From address', 'type' => 'string', 'required' => true],
            ['key' => 'from_name', 'label' => 'From name', 'type' => 'string'],
        ],
        'mysql' => [
            ['key' => 'host', 'label' => 'Host', 'type' => 'string', 'required' => true, 'default' => 'localhost'],
            ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'default' => 3306],
            ['key' => 'database', 'label' => 'Database', 'type' => 'string', 'required' => true],
            ['key' => 'username', 'label' => 'Username', 'type' => 'string', 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
        ],
    ];

    /** Credential form fields for one auth spec: the type's defaults plus any manifest-declared extras. */
    public function fields(array $authSpec): array
    {
        // Manifest fields with the same key override the defaults (e.g. relabel "API Key" as "Bot token").
        return collect(self::FIELDS[$authSpec['type']] ?? [])->keyBy('key')
            ->merge(collect($authSpec['fields'] ?? [])->keyBy('key'))
            ->values()->all();
    }

    public function authSpec(array $integration, ?Credential $credential): array
    {
        $specs = $integration['auth'];

        return $credential
            ? (collect($specs)->firstWhere('type', $credential->auth_type) ?? throw new RuntimeException("Credential [{$credential->name}] does not match {$integration['name']}'s auth."))
            : $specs[0];
    }

    /**
     * @param  array  $query  request query params; api keys sent "in: query" are added here
     */
    public function authorize(PendingRequest $request, array $authSpec, ?Credential $credential, array &$query): PendingRequest
    {
        $type = $authSpec['type'];

        if (in_array($type, ['none', 'smtp', 'mysql'], true)) {
            return $request;
        }
        if (! $credential) {
            throw new RuntimeException('This action needs a credential. Select one in the node settings.');
        }

        $data = $credential->data;

        return match ($type) {
            'api_key' => match ($authSpec['in'] ?? null) {
                'header' => $request->withHeaders([$authSpec['name'] => ($authSpec['prefix'] ?? '').$data['api_key']]),
                'query' => tap($request, function () use (&$query, $authSpec, $data) {
                    $query[$authSpec['name']] = $data['api_key'];
                }),
                default => $request, // used via {{ credentials.api_key }} in base_url/endpoint
            },
            'bearer' => $this->sendToken($request, $authSpec, $data['token'], $query),
            'basic' => $request->withBasicAuth($data['username'], $data['password']),
            'oauth2' => $this->sendToken($request, $authSpec, $this->oauthAccessToken($credential, $authSpec), $query),
            'oauth2_client_credentials' => $this->sendToken($request, $authSpec, $this->clientCredentialsToken($credential, $authSpec), $query),
            'google_service_account' => $request->withToken($this->googleAccessToken($credential, $authSpec['scopes'] ?? [])),
        };
    }

    /** OAuth URLs, scopes and params may reference credential fields, e.g. a Zoho data centre or a Dynamics org URL. */
    private function resolveSpec(Credential $credential, array $authSpec): array
    {
        return app(VariableResolver::class)->resolve($authSpec, ['credentials' => $credential->data]);
    }

    /** Authorization header by default; "token_query": "access_token" sends it as a query parameter instead. */
    private function sendToken(PendingRequest $request, array $authSpec, string $token, array &$query): PendingRequest
    {
        if (! empty($authSpec['token_query'])) {
            $query[$authSpec['token_query']] = $token;

            return $request;
        }

        return $request->withToken($token, $authSpec['token_type'] ?? 'Bearer');
    }

    /** PKCE (RFC 7636) pair for providers that require it ("pkce": true): [verifier, S256 challenge]. */
    public static function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    public function oauthAuthorizeUrl(Credential $credential, array $authSpec, string $redirectUri, string $state, ?string $codeChallenge = null): string
    {
        $authSpec = $this->resolveSpec($credential, $authSpec);
        $params = array_merge([
            'client_id' => $credential->data['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode($authSpec['scope_separator'] ?? ' ', $authSpec['scopes'] ?? []),
            'state' => $state,
        ], $codeChallenge ? ['code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256'] : [], $authSpec['authorize_params'] ?? []);

        return $authSpec['authorize_url'].(str_contains($authSpec['authorize_url'], '?') ? '&' : '?').http_build_query($params);
    }

    public function oauthExchangeCode(Credential $credential, array $authSpec, string $code, string $redirectUri, ?string $codeVerifier = null): void
    {
        $this->storeTokens($credential, $this->tokenRequest($credential, $authSpec, array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ])));
    }

    public function oauthAccessToken(Credential $credential, array $authSpec): string
    {
        $data = $credential->data;

        if (empty($data['access_token'])) {
            throw new RuntimeException("Credential [{$credential->name}] is not connected yet. Open Credentials and click Connect.");
        }

        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt && $expiresAt - 60 <= time()) {
            if (empty($data['refresh_token'])) {
                throw new RuntimeException("OAuth token for [{$credential->name}] expired and no refresh token is available. Reconnect it.");
            }

            // Some providers (Trustpilot…) refresh at a different endpoint than the code exchange.
            $refreshSpec = isset($authSpec['refresh_url']) ? ['token_url' => $authSpec['refresh_url']] + $authSpec : $authSpec;
            $this->storeTokens($credential, $this->tokenRequest($credential, $refreshSpec, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $data['refresh_token'],
            ]));
        }

        return $credential->data['access_token'];
    }

    /**
     * Machine-to-machine OAuth (client_credentials grant): fetched on demand and cached until shortly
     * before it expires. Used by PayPal, Personio, Taboola, Shopware, commercetools…
     */
    public function clientCredentialsToken(Credential $credential, array $authSpec): string
    {
        $key = 'flowforge:cc:'.$credential->id.':'.md5($credential->updated_at.json_encode($authSpec));
        if ($cached = Cache::get($key)) {
            return Crypt::decryptString($cached);
        }

        $authSpec = $this->resolveSpec($credential, $authSpec);
        $params = array_merge(['grant_type' => 'client_credentials'], (array) ($authSpec['token_params'] ?? []));
        if (! empty($authSpec['scopes'])) {
            $params['scope'] = implode($authSpec['scope_separator'] ?? ' ', $authSpec['scopes']);
        }
        $tokens = $this->tokenRequest($credential, $authSpec, $params);

        $ttl = max(60, (int) ($tokens['expires_in'] ?? 3600) - 120);
        Cache::put($key, Crypt::encryptString($tokens['access_token']), $ttl);

        return $tokens['access_token'];
    }

    public function googleAccessToken(Credential $credential, array $scopes): string
    {
        $key = 'flowforge:gsa:'.$credential->id.':'.md5($credential->updated_at.implode(' ', $scopes));

        if ($cached = Cache::get($key)) {
            return Crypt::decryptString($cached);
        }

        $account = json_decode($credential->data['service_account_json'] ?? '', true);
        if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new RuntimeException('The service account JSON key is invalid (missing client_email or private_key).');
        }

        $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss' => $account['client_email'],
                'scope' => implode(' ', $scopes),
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the service account JWT (bad private_key?).');
        }
        $segments[] = $this->base64Url($signature);

        $response = Http::asForm()->timeout(20)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('Google token exchange failed: '.Str::limit($response->body(), 300));
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);
        Cache::put($key, Crypt::encryptString($response->json('access_token')), $ttl);

        return $response->json('access_token');
    }

    private function tokenRequest(Credential $credential, array $authSpec, array $params): array
    {
        $authSpec = $this->resolveSpec($credential, $authSpec);
        $request = Http::asForm()->acceptJson()->timeout(20);

        // Some providers (Zoom, RingCentral, Reddit, Notion…) only accept client credentials as HTTP Basic.
        if (($authSpec['client_auth'] ?? 'body') === 'basic') {
            $request->withBasicAuth($credential->data['client_id'], $credential->data['client_secret']);
        } else {
            $params += ['client_id' => $credential->data['client_id'], 'client_secret' => $credential->data['client_secret']];
        }

        $response = $request->post($authSpec['token_url'], $params);

        $tokens = $response->json();
        if ($response->failed() || ! is_array($tokens) || empty($tokens['access_token'])) {
            throw new RuntimeException('OAuth token request failed: '.Str::limit($response->body(), 300));
        }

        return $tokens;
    }

    private function storeTokens(Credential $credential, array $tokens): void
    {
        $credential->data = array_merge($credential->data, [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $credential->data['refresh_token'] ?? null,
            'expires_at' => isset($tokens['expires_in']) ? time() + (int) $tokens['expires_in'] : null,
            'token_type' => $tokens['token_type'] ?? 'Bearer',
        ]);
        $credential->save();
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
