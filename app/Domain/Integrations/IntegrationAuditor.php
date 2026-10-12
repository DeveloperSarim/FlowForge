<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\TemplateLibrary;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Static + dry-run checks for every manifest (and template). For each HTTP action / polling trigger it
 * builds sample inputs and a fake credential, runs the real executor against a faked HTTP client and
 * checks the request that would go out: valid URL on the app's host, no unresolved {{ }} anywhere,
 * every referenced input/credential field declared.
 */
class IntegrationAuditor
{
    private const PLACEHOLDER = '/\{\{\s*(inputs|credentials)\.([A-Za-z0-9_\-]+)/';

    private ?string $serviceAccountJson = null;

    public function __construct(
        private ManifestLoader $manifests,
        private WorkflowActionExecutor $executor,
        private CredentialVault $vault,
    ) {}

    /**
     * @return array{apps: int, operations: int, issues: list<array{app: string, where: string, level: string, message: string}>}
     */
    public function audit(?string $only = null, bool $live = false): array
    {
        $issues = [];
        $operations = 0;
        $apps = 0;

        foreach ($this->manifests->catalog() as $entry) {
            if ($only && ! str_starts_with($entry['key'], $only) && $entry['category'] !== $only) {
                continue;
            }
            $manifest = $this->manifests->get($entry['key']);
            $apps++;
            $add = function (string $where, string $message, string $level = 'error') use (&$issues, $manifest) {
                $issues[] = ['app' => $manifest['key'], 'where' => $where, 'level' => $level, 'message' => $message];
            };

            $this->staticChecks($manifest, $add);

            foreach ([...$manifest['actions'], ...array_filter($manifest['triggers'], fn ($t) => $t['type'] === 'polling')] as $operation) {
                if (isset($operation['handler'])) {
                    continue;
                }
                $operations++;
                $this->dryRun($manifest, $operation, $add);
                if ($live) {
                    $this->liveCall($manifest, $operation, $add);
                }
            }

            foreach ($manifest['triggers'] as $trigger) {
                if ($trigger['type'] === 'webhook' && ! collect($trigger['inputs'])->contains('key', 'sample_payload')) {
                    $add("trigger {$trigger['key']}", 'Webhook trigger has no sample_payload input (test runs will have no data).', 'warning');
                }
                if ($trigger['type'] === 'polling' && empty($trigger['id_field'])) {
                    $add("trigger {$trigger['key']}", 'Polling trigger without id_field falls back to hashing items.', 'warning');
                }
            }
        }

        return ['apps' => $apps, 'operations' => $operations, 'issues' => $issues];
    }

    /** @return list<array{template: string, level: string, message: string}> */
    public function auditTemplates(TemplateLibrary $library): array
    {
        $issues = [];
        foreach (glob(resource_path('templates/*.json')) as $file) {
            $template = json_decode(file_get_contents($file), true);
            $key = $template['key'] ?? basename($file);
            $add = function (string $message, string $level = 'error') use (&$issues, $key) {
                $issues[] = ['template' => $key, 'level' => $level, 'message' => $message];
            };

            if (! is_array($template) || empty($template['graph']['nodes'])) {
                $add('Not a valid template file.');

                continue;
            }

            $ids = array_column($template['graph']['nodes'], 'id');
            foreach ($template['graph']['nodes'] as $node) {
                $data = $node['data'] ?? [];
                $manifest = $this->manifests->get($data['integration'] ?? '');
                if (! $manifest) {
                    $add("Node {$node['id']}: app [{$data['integration']}] is not installed.");

                    continue;
                }
                $operation = $node['type'] === 'trigger'
                    ? collect($manifest['triggers'])->firstWhere('key', $data['trigger'] ?? '')
                    : collect($manifest['actions'])->firstWhere('key', $data['action'] ?? '');
                if (! $operation) {
                    $add("Node {$node['id']}: {$manifest['key']} has no ".($node['type'] === 'trigger' ? 'trigger' : 'action').' ['.($data['trigger'] ?? $data['action'] ?? '?').'].');

                    continue;
                }
                if (($node['type'] === 'branch') !== ! empty($operation['outputs'])) {
                    $add("Node {$node['id']}: node type [{$node['type']}] doesn't match the action's outputs.");
                }
                $declared = array_column($operation['inputs'], 'key');
                foreach (array_keys($data['config'] ?? []) as $configKey) {
                    if (! in_array($configKey, $declared, true)) {
                        $add("Node {$node['id']}: config key [$configKey] is not an input of {$manifest['key']}.{$operation['key']}.");
                    }
                }
            }
            foreach ($template['graph']['edges'] ?? [] as $edge) {
                if (! in_array($edge['source'], $ids, true) || ! in_array($edge['target'], $ids, true)) {
                    $add("Edge {$edge['source']} → {$edge['target']} points at a missing node.");
                }
            }
        }

        return $issues;
    }

    private function staticChecks(array $manifest, callable $add): void
    {
        $core = in_array($manifest['category'], ['Triggers', 'Core', 'Flow control', 'Data transformation'], true);

        if (! $core && in_array($manifest['icon'], ['plug', ''], true)) {
            $add('logo', 'No logo file generated (run scripts/build-integration-icons.mjs).', 'warning');
        }
        if (trim($manifest['description']) === '') {
            $add('manifest', 'Missing description.', 'warning');
        }

        $credentialFields = [];
        foreach ($manifest['auth'] as $spec) {
            if ($spec['type'] === 'api_key' && isset($spec['in']) && empty($spec['name'])) {
                $add('auth', 'api_key with "in" needs a "name".');
            }
            if ($spec['type'] === 'oauth2' && empty($spec['scopes']) && ! str_contains($spec['authorize_url'] ?? '', 'scope')) {
                $add('auth', 'OAuth2 without scopes (fine only if the provider needs none).', 'warning');
            }
            $credentialFields = [...$credentialFields, ...array_column($this->vault->fields($spec), 'key')];
        }

        $json = json_encode([$manifest['base_url'] ?? '', $manifest['headers'] ?? [], $manifest['actions'], $manifest['triggers'], $manifest['auth']]);
        preg_match_all('/\{\{\s*credentials\.([A-Za-z0-9_\-]+)/', $json, $m);
        foreach (array_unique($m[1]) as $field) {
            if (! in_array($field, $credentialFields, true)) {
                $add('credentials', "Uses {{ credentials.$field }} but no auth method defines a [$field] field.");
            }
        }

        foreach ([...$manifest['actions'], ...$manifest['triggers']] as $operation) {
            $where = (isset($operation['type']) ? 'trigger ' : 'action ').$operation['key'];
            $declared = array_column($operation['inputs'], 'key');

            if (count($declared) !== count(array_unique($declared))) {
                $add($where, 'Duplicate input keys.');
            }
            $templates = json_encode([$operation['method'] ?? '', $operation['endpoint'] ?? '', $operation['query'] ?? [], $operation['headers'] ?? [], $operation['body'] ?? null, $operation['body_format'] ?? '']);
            preg_match_all('/\{\{\s*inputs\.([A-Za-z0-9_\-]+)/', $templates, $refs);
            foreach (array_unique($refs[1]) as $ref) {
                if (! in_array($ref, $declared, true)) {
                    $add($where, "Template uses {{ inputs.$ref }} but there is no such input.");
                }
            }
            foreach ($operation['inputs'] as $input) {
                if ($input['type'] === 'select') {
                    $values = array_map('strval', array_column($input['options'] ?? [], 'value'));
                    if ($values === []) {
                        $add($where, "Select input [{$input['key']}] has no options.");
                    } elseif (isset($input['default']) && ! in_array((string) $input['default'], $values, true)) {
                        $add($where, "Default of [{$input['key']}] is not one of its options.", 'warning');
                    }
                }
                if (! isset($operation['handler']) && in_array($operation['type'] ?? 'polling', ['polling'], true) && ! in_array($input['key'], $refs[1], true)
                    && ! in_array($input['key'], ['poll_interval', 'sample_payload'], true)) {
                    $add($where, "Input [{$input['key']}] is never used in the request.", 'warning');
                }
            }
        }
    }

    private function dryRun(array $manifest, array $operation, callable $add): void
    {
        $where = (isset($operation['type']) ? 'trigger ' : 'action ').$operation['key'];
        $spec = collect($manifest['auth'])->first(fn ($a) => $a['type'] !== 'none') ?? $manifest['auth'][0];
        $credential = $spec['type'] === 'none' ? null : $this->fakeCredential($manifest['key'], $spec, $manifest);
        $sent = [];

        Http::fake(function (ClientRequest $request) use (&$sent) {
            $sent[] = $request;
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]);
            }

            return Http::response(['ok' => true, 'id' => 1, 'access_token' => 'fake-token', 'expires_in' => 3600, 'data' => [['id' => 1]], 'items' => [['id' => 1]], 'results' => [['id' => 1]]], 200);
        });

        try {
            $this->executor->run($manifest, $operation, ['config' => $this->sampleConfig($operation['inputs'])], ['steps' => []], $credential);
        } catch (Throwable $e) {
            $add($where, 'Dry run failed: '.$e->getMessage());

            return;
        } finally {
            Http::swap(new Factory);
        }

        $tokenUrls = array_filter(array_column($manifest['auth'], 'token_url'));
        $request = collect($sent)->last(fn ($r) => ! str_contains($r->url(), 'oauth2.googleapis.com/token')
            && ! collect($tokenUrls)->contains(fn ($u) => str_contains($r->url(), (string) parse_url(str_replace(['{{', '}}'], '', $u), PHP_URL_PATH) ?: '#')));
        if (! $request) {
            $add($where, 'Dry run sent no request.');

            return;
        }

        $url = $request->url();
        $strings = [$url, $request->body(), ...array_merge(...array_values($request->headers()))];
        if (collect($strings)->contains(fn ($value) => preg_match('/\{\{\s*[A-Za-z_]/', urldecode((string) $value)))) {
            $add($where, "Unresolved template in the request: {$url}");
        }
        if (! preg_match('#^https?://[^/\s]+#i', $url)) {
            $add($where, "Request URL is not absolute: {$url}");
        }
        if (! empty($manifest['base_url']) && ! preg_match('#^\{\{\s*inputs\.#', (string) ($operation['endpoint'] ?? ''))) {
            $baseHost = parse_url(str_replace('{{', '', $this->resolveBase($manifest, $credential)), PHP_URL_HOST);
            $host = parse_url($url, PHP_URL_HOST);
            if ($baseHost && $host && $baseHost !== $host && ! preg_match('#^https?://#', (string) ($operation['endpoint'] ?? ''))) {
                $add($where, "Request went to {$host}, not the app host {$baseHost}.", 'warning');
            }
        }
    }

    private function liveCall(array $manifest, array $operation, callable $add): void
    {
        $authTypes = array_column($manifest['auth'], 'type');
        if (! in_array('none', $authTypes, true) || ($operation['method'] ?? 'GET') !== 'GET') {
            return;
        }
        $config = [];
        foreach ($operation['inputs'] as $input) {
            $value = $input['default'] ?? $input['placeholder'] ?? null;
            if (! empty($input['required']) && ($value === null || $value === '')) {
                return; // can't call without a meaningful value
            }
            $config[$input['key']] = $value;
        }

        try {
            $result = $this->executor->run($manifest, $operation, ['config' => $config], ['steps' => []]);
            $add('live '.$operation['key'], "Live call OK (HTTP {$result->httpStatus}).", 'info');
        } catch (Throwable $e) {
            $add('live '.$operation['key'], 'Live call failed: '.$e->getMessage(), 'warning');
        }
    }

    private function resolveBase(array $manifest, ?Credential $credential): string
    {
        return (string) app(VariableResolver::class)->resolve($manifest['base_url'] ?? '', ['credentials' => $credential?->data ?? []]);
    }

    private function sampleConfig(array $inputs): array
    {
        $config = [];
        foreach ($inputs as $input) {
            $config[$input['key']] = $input['default'] ?? match ($input['type']) {
                'number' => 5,
                'boolean' => true,
                'select' => $input['options'][0]['value'] ?? 'x',
                'json' => '{"name": "Sample"}',
                'list' => ['alpha', 'beta'],
                'keyvalue' => [['key' => 'name', 'value' => 'Sample']],
                'conditions' => [['left' => 'a', 'operator' => 'equals', 'right' => 'a']],
                'code' => 'SELECT 1',
                default => str_contains(strtolower($input['key']), 'email') ? 'ada@example.com'
                    : (str_contains(strtolower($input['key']), 'url') ? 'https://example.com/hook' : 'sample'.preg_replace('/[^a-z0-9]/', '', strtolower($input['key']))),
            };
        }

        return $config;
    }

    private function fakeCredential(string $integration, array $spec, array $manifest = []): Credential
    {
        // Fields used as the whole origin of base_url (self-hosted URL, cluster endpoint…) need a real URL.
        preg_match('/^\{\{\s*credentials\.([A-Za-z0-9_\-]+)/', (string) ($manifest['base_url'] ?? ''), $origin);

        $data = [];
        foreach ($this->vault->fields($spec) as $field) {
            $key = $field['key'];
            $looksLikeUrl = ($origin[1] ?? null) === $key || preg_match('/(^|_)(url|endpoint|site|instance|server|base)($|_)/i', $key);
            $placeholder = (string) ($field['placeholder'] ?? '');
            $data[$key] = $field['default'] ?? match (true) {
                $looksLikeUrl => preg_match('#^https?://[\w.\-]+(/\S*)?$#', $placeholder) ? $placeholder : 'https://audit.example.com',
                ($field['type'] ?? '') === 'number' => 1,
                ($field['type'] ?? '') === 'select' => $field['options'][0]['value'] ?? 'x',
                default => 'test'.preg_replace('/[^a-z0-9]/', '', strtolower($key)),
            };
        }
        if ($spec['type'] === 'oauth2') {
            $data += ['access_token' => 'test-access-token', 'refresh_token' => 'test-refresh', 'expires_at' => time() + 86400];
        }
        if ($spec['type'] === 'google_service_account') {
            $data['service_account_json'] = $this->serviceAccountJson();
        }

        $credential = new Credential(['name' => 'audit', 'integration' => $integration, 'auth_type' => $spec['type']]);
        $credential->data = $data;
        $credential->id = 0;

        return $credential;
    }

    /** A throwaway RSA key so the service-account JWT path really signs. */
    private function serviceAccountJson(): string
    {
        if ($this->serviceAccountJson === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $this->serviceAccountJson = json_encode(['client_email' => 'audit@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
        }

        return $this->serviceAccountJson;
    }
}
