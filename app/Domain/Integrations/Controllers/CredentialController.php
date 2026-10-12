<?php

namespace App\Domain\Integrations\Controllers;

use App\Domain\Integrations\CredentialVault;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The credential vault UI. Secret fields are write-only: they are never sent back to the browser,
 * and leaving one blank on edit keeps the stored value.
 */
class CredentialController extends Controller
{
    private const SECRET_TYPES = ['password', 'code'];

    public function __construct(private ManifestLoader $manifests, private CredentialVault $vault) {}

    public function index(): Response
    {
        $catalog = $this->manifests->catalogMap();

        return Inertia::render('Credentials/Index', [
            'credentials' => Credential::query()->orderBy('name')->get()->map(function (Credential $credential) use ($catalog) {
                $spec = collect($this->manifests->get($credential->integration)['auth'] ?? [])->firstWhere('type', $credential->auth_type);
                $fields = $spec ? $this->vault->fields($spec) : [];

                return [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'integration' => $credential->integration,
                    'integration_name' => $catalog[$credential->integration]['name'] ?? $credential->integration,
                    'icon' => $catalog[$credential->integration]['icon'] ?? 'plug',
                    'auth_type' => $credential->auth_type,
                    'connected' => $credential->auth_type !== 'oauth2' || ! empty($credential->data['access_token']),
                    'account' => $credential->data['account_name'] ?? null,
                    'values' => collect($fields)
                        ->reject(fn ($field) => in_array($field['type'] ?? 'string', self::SECRET_TYPES, true))
                        ->mapWithKeys(fn ($field) => [$field['key'] => $credential->data[$field['key']] ?? null])
                        ->all(),
                    'updated_at' => $credential->updated_at?->toIso8601String(),
                ];
            }),
            // Field definitions are fetched per app from /integrations/{key} when one is picked.
            'integrations' => collect($catalog)
                ->filter(fn ($app) => $app['auth'] !== [])
                ->map(fn ($app) => ['key' => $app['key'], 'name' => $app['name'], 'icon' => $app['icon'], 'category' => $app['category'], 'popular' => $app['popular']])
                ->values(),
            'redirectUri' => url('/oauth/callback'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'integration' => ['required', 'string'],
            'auth_type' => ['required', 'string'],
            'data' => ['nullable', 'array'],
        ]);

        $spec = $this->spec($data['integration'], $data['auth_type']);

        $credential = Credential::query()->create([
            'name' => $data['name'],
            'integration' => $data['integration'],
            'auth_type' => $data['auth_type'],
            'data' => $this->fieldValues($this->vault->fields($spec), $data['data'] ?? [], []),
        ]);

        return back()->with('success', $credential->auth_type === 'oauth2'
            ? "Saved \"{$credential->name}\". Click Connect to authorize it."
            : "Saved \"{$credential->name}\".");
    }

    public function update(Request $request, Credential $credential): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'data' => ['nullable', 'array'],
        ]);

        $spec = $this->spec($credential->integration, $credential->auth_type);
        $credential->update([
            'name' => $data['name'],
            'data' => array_merge(
                $credential->data, // keeps OAuth tokens
                $this->fieldValues($this->vault->fields($spec), $data['data'] ?? [], $credential->data),
            ),
        ]);

        return back()->with('success', "Updated \"{$credential->name}\".");
    }

    public function destroy(Credential $credential): RedirectResponse
    {
        $credential->delete();

        return back()->with('success', "Deleted \"{$credential->name}\".");
    }

    public function connect(Request $request, Credential $credential): HttpResponse
    {
        $spec = $this->spec($credential->integration, $credential->auth_type);
        abort_unless($spec['type'] === 'oauth2', 400, 'Not an OAuth2 credential.');

        $state = Str::random(40);
        [$verifier, $challenge] = ! empty($spec['pkce']) ? CredentialVault::pkcePair() : [null, null];
        $request->session()->put('oauth_state', ['state' => $state, 'credential' => $credential->id, 'verifier' => $verifier]);

        return Inertia::location($this->vault->oauthAuthorizeUrl($credential, $spec, url('/oauth/callback'), $state, $challenge));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('oauth_state');

        if (! $expected || ! hash_equals($expected['state'], (string) $request->query('state'))) {
            return redirect('/credentials')->with('error', 'OAuth state mismatch — start the connection again.');
        }
        if ($error = $request->query('error')) {
            return redirect('/credentials')->with('error', 'Authorization was denied: '.$request->query('error_description', $error));
        }

        $credential = Credential::query()->findOrFail($expected['credential']);

        try {
            $this->vault->oauthExchangeCode(
                $credential,
                $this->spec($credential->integration, $credential->auth_type),
                (string) $request->query('code'),
                url('/oauth/callback'),
                $expected['verifier'] ?? null,
            );
        } catch (Throwable $e) {
            return redirect('/credentials')->with('error', $e->getMessage());
        }

        $manifest = $this->manifests->get($credential->integration);
        if (isset($this->spec($credential->integration, $credential->auth_type)['profile'])) {
            try {
                $account = app(WorkflowActionExecutor::class)->profile($manifest, $credential->refresh())['account_name'] ?? null;
            } catch (Throwable) {
                $account = null; // looked up again on first use
            }
        }

        return redirect('/credentials')->with('success', "Connected \"{$credential->name}\"".(! empty($account) ? " as {$account}." : '.'));
    }

    private function spec(string $integration, string $authType): array
    {
        $manifest = $this->manifests->get($integration)
            ?? throw ValidationException::withMessages(['integration' => 'Unknown integration.']);

        return collect($manifest['auth'])->firstWhere('type', $authType)
            ?? throw ValidationException::withMessages(['auth_type' => "{$manifest['name']} does not support {$authType}."]);
    }

    private function fieldValues(array $fields, array $input, array $existing): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if (($value === null || $value === '') && in_array($field['type'] ?? 'string', self::SECRET_TYPES, true) && isset($existing[$key])) {
                $value = $existing[$key]; // blank secret on edit = keep
            }
            if (($value === null || $value === '') && ! empty($field['required'])) {
                $errors["data.$key"] = ($field['label'] ?? $key).' is required.';
            }
            if ($key === 'service_account_json' && $value) {
                $json = json_decode($value, true);
                if (empty($json['client_email']) || empty($json['private_key'])) {
                    $errors["data.$key"] = 'Paste the full service account JSON key (needs client_email and private_key).';
                }
            }

            $values[$key] = $value ?? ($field['default'] ?? null);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }
}
