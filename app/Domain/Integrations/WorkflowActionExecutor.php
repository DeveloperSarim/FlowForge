<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Handlers\DatabaseHandler;
use App\Domain\Integrations\Handlers\LogicHandler;
use App\Domain\Integrations\Handlers\MailHandler;
use App\Domain\Integrations\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes one manifest operation (action, or polling trigger) for a node:
 * 1. resolve the node's configured inputs against the run context ({{ steps.* }})
 * 2. either call a built-in handler, or render the manifest's HTTP template with
 *    {{ inputs.* }} / {{ credentials.* }} and send it with the credential attached.
 */
class WorkflowActionExecutor
{
    public const HANDLERS = [
        'if' => LogicHandler::class,
        'filter' => LogicHandler::class,
        'json_parse' => LogicHandler::class,
        'text_format' => LogicHandler::class,
        'smtp' => MailHandler::class,
        'mysql' => DatabaseHandler::class,
    ];

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    public function __construct(
        private ManifestLoader $manifests,
        private VariableResolver $resolver,
        private CredentialVault $vault,
    ) {}

    public function execute(array $nodeData, array $context): ActionResult
    {
        $integration = $this->integration($nodeData['integration'] ?? '');
        $action = collect($integration['actions'])->firstWhere('key', $nodeData['action'] ?? '')
            ?? throw new ActionException("Action [{$integration['key']}.".($nodeData['action'] ?? '?').'] does not exist.');

        return $this->run($integration, $action, $nodeData, $context);
    }

    public function integration(string $key): array
    {
        return $this->manifests->get($key) ?? throw new ActionException("Integration [$key] is not installed.");
    }

    /**
     * @param  Credential|null  $credential  an in-memory credential (used by the auditor); otherwise loaded from credential_id
     */
    public function run(array $integration, array $operation, array $nodeData, array $context, ?Credential $credential = null): ActionResult
    {
        $inputs = [];

        try {
            $inputs = $this->resolveInputs($operation['inputs'] ?? [], $nodeData['config'] ?? [], $context);
            $credential ??= $this->credential($integration, $nodeData['credential_id'] ?? null);
            $inputs = $this->prepareInputs($integration, $operation['inputs'] ?? [], $inputs, $credential);

            $result = isset($operation['handler'])
                ? app(self::HANDLERS[$operation['handler']])->handle($operation['handler'], $inputs, $credential)
                : $this->http($integration, $operation, $inputs, $credential);
            $result->input = $inputs;

            return $result;
        } catch (Throwable $e) {
            $error = $e instanceof ActionException ? $e : new ActionException($e->getMessage(), previous: $e);
            $error->input = $inputs;

            throw $error;
        }
    }

    /**
     * Resolve node config against the run context and coerce each value to its declared input type.
     */
    public function resolveInputs(array $definitions, array $config, array $context): array
    {
        $inputs = [];

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            $value = $this->coerce($definition, $config[$key] ?? $definition['default'] ?? null, $context);

            if ($value === null || $value === '' || $value === []) {
                if (! empty($definition['required'])) {
                    throw new ActionException("\"{$definition['label']}\" is required.");
                }
                $value = null;
            }

            $inputs[$key] = $value;
        }

        return $inputs;
    }

    /**
     * Input options that need the credential: "fallback": "credentials.person_urn" fills an empty input from
     * the connected account (looked up once via the auth spec's "profile"); "escape": "little_text" applies
     * LinkedIn's reserved-character escaping and turns #tags into real hashtags.
     */
    private function prepareInputs(array $integration, array $definitions, array $inputs, ?Credential $credential): array
    {
        foreach ($definitions as $definition) {
            $key = $definition['key'];
            if (isset($definition['fallback']) && ($inputs[$key] ?? null) === null && $credential) {
                $field = Str::after($definition['fallback'], 'credentials.');
                $data = $credential->data;
                if (blank($data[$field] ?? null)) {
                    $data = $this->profile($integration, $credential, $definition['label']);
                }
                $inputs[$key] = $data[$field] ?? throw new ActionException("Fill in \"{$definition['label']}\": it could not be read from the connected account.");
            }
            if (($definition['escape'] ?? null) === 'little_text' && is_string($inputs[$key] ?? null)) {
                $text = preg_replace('/([\\\\|{}@\[\]()<>*_~])/', '\\\\$1', $inputs[$key]);
                $text = preg_replace('/(?<![\w\\\\])#(\w+)/u', '{hashtag|\\\\#|$1}', $text);
                $inputs[$key] = preg_replace('/(?<![\\\\{|])#/', '\\\\#', $text);
            }
        }

        return $inputs;
    }

    /**
     * Reads the connected account (auth spec "profile": {endpoint, store: {field: template}}) and saves the
     * resolved fields on the credential, e.g. LinkedIn's /v2/userinfo "sub" as person_urn.
     */
    public function profile(array $integration, Credential $credential, string $for = 'this field'): array
    {
        $spec = $this->vault->authSpec($integration, $credential);
        $profile = $spec['profile'] ?? throw new ActionException("Fill in \"{$for}\".");
        $context = ['credentials' => $credential->data];

        $endpoint = $this->resolver->stringify($this->resolver->resolve($profile['endpoint'], $context));
        $url = preg_match('#^https?://#i', $endpoint) ? $endpoint
            : rtrim($this->resolver->stringify($this->resolver->resolve($integration['base_url'] ?? '', $context)), '/').'/'.ltrim($endpoint, '/');
        $query = [];
        $request = $this->vault->authorize(Http::timeout(20)->withUserAgent('FlowForge/1.0')->acceptJson(), $spec, $credential, $query);
        $response = $request->get($url, $query);

        if ($response->failed() || ! is_array($response->json())) {
            throw new ActionException("Could not read your {$integration['name']} account (HTTP {$response->status()}). Reconnect the credential, or fill in \"{$for}\" yourself.", $response->status(), $response->json());
        }

        $values = [];
        foreach ($profile['store'] as $field => $template) {
            preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $template, $refs);
            if (collect($refs[1])->every(fn ($ref) => filled(data_get($response->json(), $ref)))) {
                $values[$field] = $this->resolver->stringify($this->resolver->resolve($template, $response->json()));
            }
        }
        $credential->data = array_merge($credential->data, $values);
        if ($credential->exists) {
            $credential->save();
        }

        return $credential->data;
    }

    private function coerce(array $definition, mixed $raw, array $context): mixed
    {
        $label = $definition['label'];

        switch ($definition['type']) {
            case 'json':
                return $this->json($raw, $context, $label);

            case 'keyvalue':
                $pairs = [];
                foreach (is_array($raw) ? $raw : [] as $row) {
                    $name = $this->resolver->stringify($this->resolver->resolve($row['key'] ?? '', $context));
                    if ($name !== '') {
                        $pairs[$name] = $this->resolver->resolve($row['value'] ?? '', $context);
                    }
                }

                return $pairs;

            case 'list':
                $value = $this->resolver->resolve($raw, $context);
                if (is_string($value) && str_starts_with(ltrim($value), '[')) {
                    $value = json_decode($value, true) ?? $value;
                }

                // A single "{{ steps.x.items }}" entry that resolves to a list means "use that list".
                if (is_array($value) && count($value) === 1 && is_array(reset($value)) && array_is_list(reset($value))) {
                    $value = reset($value);
                }

                return is_array($value) ? array_values($value) : ($value === null || $value === '' ? [] : [$value]);

            case 'conditions':
                return is_array($raw) ? array_values(array_map(fn ($condition) => [
                    'left' => $this->resolver->resolve($condition['left'] ?? null, $context),
                    'operator' => $condition['operator'] ?? 'equals',
                    'right' => $this->resolver->resolve($condition['right'] ?? null, $context),
                ], $raw)) : [];

            case 'number':
                $value = $this->resolver->resolve($raw, $context);
                if ($value === null || $value === '') {
                    return null;
                }
                if (! is_numeric($value)) {
                    throw new ActionException("\"{$label}\" must be a number, got: ".Str::limit($this->resolver->stringify($value), 60));
                }

                return $value + 0;

            case 'boolean':
                $value = $this->resolver->resolve($raw, $context);
                if ($value === null || $value === '') {
                    return null; // not set: leave it out of the request instead of sending false
                }

                return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            default: // string, text, password, select, code
                $value = $this->resolver->resolve($raw, $context);

                return is_array($value) || is_object($value) ? $this->resolver->stringify($value) : $value;
        }
    }

    private function json(mixed $raw, array $context, string $label): mixed
    {
        if (is_array($raw)) {
            return $this->resolver->resolve($raw, $context);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Parse the template first so substituted values can never break the JSON syntax.
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->resolver->resolve($decoded, $context);
        }

        // Template is only valid after substitution, e.g. {"count": {{ steps.a.count }} }.
        $resolved = $this->resolver->resolve($raw, $context);
        if (! is_string($resolved)) {
            return $resolved;
        }

        $decoded = json_decode($resolved, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ActionException("\"{$label}\" is not valid JSON: ".json_last_error_msg());
        }

        return $decoded;
    }

    private function credential(array $integration, mixed $id): ?Credential
    {
        if (! $id) {
            return null;
        }

        $credential = Credential::query()->find($id) ?? throw new ActionException('The selected credential no longer exists.');

        if ($credential->integration !== $integration['key']) {
            throw new ActionException("Credential [{$credential->name}] belongs to another integration.");
        }

        return $credential;
    }

    private function http(array $integration, array $operation, array $inputs, ?Credential $credential): ActionResult
    {
        $context = ['inputs' => $inputs, 'credentials' => $credential?->data ?? []];
        $resolve = fn (mixed $template, ?callable $encode = null) => $this->resolver->resolve($template, $context, $encode);

        $method = strtoupper($this->resolver->stringify($resolve($operation['method'] ?? 'GET')));
        if (! in_array($method, self::METHODS, true)) {
            throw new ActionException("Unsupported HTTP method [$method].");
        }

        // Values interpolated into the path are URL-encoded; a whole-endpoint placeholder (a full URL) is not.
        $endpoint = $this->resolver->stringify($resolve($operation['endpoint'] ?? '', 'rawurlencode'));
        $url = preg_match('#^https?://#i', $endpoint)
            ? $endpoint
            : rtrim($this->resolver->stringify($resolve($integration['base_url'] ?? '')), '/').'/'.ltrim($endpoint, '/');

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw new ActionException("Invalid request URL [$url].");
        }

        // "Make an API call" must not ship the app's credential to some other host.
        if (! empty($operation['restrict_to_base_url'])) {
            $base = rtrim($this->resolver->stringify($resolve($integration['base_url'] ?? '')), '/');
            if (! str_starts_with($url, $base.'/') && $url !== $base) {
                throw new ActionException("The path must stay on {$base}. Use the HTTP Request node for other hosts.");
            }
        }

        $query = $this->stringifyBooleans($this->compact((array) $resolve($operation['query'] ?? [])));
        $headers = array_map(
            fn ($value) => $this->resolver->stringify($value),
            $this->compact(array_merge((array) $resolve($integration['headers'] ?? []), (array) $resolve($operation['headers'] ?? [])))
        );
        $body = isset($operation['body']) ? $resolve($operation['body']) : null;
        $body = is_array($body) ? $this->spread($this->compact($body)) : $body;

        $request = Http::timeout((int) ($operation['timeout'] ?? config('flowforge.http_timeout', 30)))
            ->withUserAgent('FlowForge/1.0')
            ->acceptJson()
            ->withHeaders(array_filter($headers, fn ($name) => strtolower($name) !== 'content-type', ARRAY_FILTER_USE_KEY));

        $this->vault->authorize($request, $this->vault->authSpec($integration, $credential), $credential, $query);

        $options = $query ? ['query' => $query] : [];
        if ($body !== null && $body !== [] && $body !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
            if ($resolve($operation['body_format'] ?? 'json') === 'form') {
                $request->asForm();
                $options['form_params'] = $this->stringifyBooleans((array) $body);
            } elseif (is_array($body) && ! $this->customContentType($headers)) {
                $options['json'] = $body;
            } elseif (is_array($body)) {
                // e.g. application/json-patch+json or application/vnd.api+json: JSON payload, custom media type.
                $type = $this->customContentType($headers);
                $request->withBody(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $type);
                $options['body'] = true;
            } else {
                $request->withBody((string) $body, $this->declaredContentType($headers) ?? 'text/plain');
                $options['body'] = (string) $body;
            }
        }

        $log = ['method' => $method, 'url' => $url.($query ? '?'.http_build_query($query) : '')];

        try {
            $response = $request->send($method, $url, $options);
        } catch (ConnectionException $e) {
            throw new ActionException('Connection failed: '.$e->getMessage(), request: $log, previous: $e);
        }

        $output = $response->json() ?? ($response->body() === '' ? null : $response->body());

        if ($response->failed()) {
            $hint = $operation['error_hints'][$response->status()] ?? $integration['error_hints'][$response->status()] ?? null;
            throw new ActionException(
                "HTTP {$response->status()}: ".Str::limit(is_string($output) ? strip_tags($output) : json_encode($output, JSON_UNESCAPED_SLASHES), 300).($hint ? " — {$hint}" : ''),
                $response->status(), $output, $log,
            );
        }

        if (($path = $operation['error_path'] ?? null) && ($error = data_get($output, $path))) {
            throw new ActionException('API error: '.$this->resolver->stringify($error), $response->status(), $output, $log);
        }

        if ($path = $operation['output_path'] ?? null) {
            $output = data_get($output, $path);
        }

        // Convenience fields, e.g. {"text": "content.*.text"}: wildcard matches drop nulls, strings are joined.
        if (is_array($output) && ! empty($operation['output_fields'])) {
            foreach ($operation['output_fields'] as $field => $fieldPath) {
                $value = data_get($output, $fieldPath);
                if (is_array($value) && array_is_list($value)) {
                    $value = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                    $value = array_filter($value, 'is_string') === $value ? implode("\n", $value) : $value;
                }
                $output[$field] = $value;
            }
        }

        return new ActionResult(output: $output, httpStatus: $response->status(), request: $log);
    }

    private function declaredContentType(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type' && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** A Content-Type other than plain application/json declared by the manifest/step headers. */
    private function customContentType(array $headers): ?string
    {
        $type = $this->declaredContentType($headers);

        return $type !== null && strtolower($type) !== 'application/json' ? $type : null;
    }

    /**
     * `"...": {{ inputs.extra }}` inside an object merges that keyvalue/json input into the object;
     * explicitly named fields win over spread ones.
     */
    private function spread(array $data): array
    {
        $spread = isset($data['...']) && is_array($data['...']) ? $data['...'] : [];
        unset($data['...']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->spread($value);
            }
        }

        return $spread === [] ? $data : array_merge($spread, $data);
    }

    /**
     * Drop null entries from objects so blank optional inputs are not sent (lists keep their shape).
     */
    private function compact(array $data): array
    {
        $isList = array_is_list($data);
        $result = [];

        foreach ($data as $key => $value) {
            if ($value === null && ! $isList) {
                continue;
            }
            if (is_array($value) && array_key_exists('$if', $value)) {
                // {"$if": "{{ inputs.system }}", "role": "system", …} is kept only when the condition is set.
                if (in_array($value['$if'], [null, '', false, []], true)) {
                    continue;
                }
                unset($value['$if']);
            }
            if (is_array($value) && $value !== []) {
                $value = $this->compact($value);
                if ($value === [] && ! $isList) {
                    continue;
                }
            }
            $result[$key] = $value;
        }

        return $isList ? array_values($result) : $result;
    }

    /** Query strings and form bodies need "true"/"false", not PHP's "1"/"". */
    private function stringifyBooleans(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
        });

        return $data;
    }
}
