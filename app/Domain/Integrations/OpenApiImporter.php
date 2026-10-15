<?php

namespace App\Domain\Integrations;

use Illuminate\Support\Str;

/**
 * Turns an OpenAPI v3 document into an integration manifest: one action per operation.
 * Optional top-level "x-flowforge": {key, name, icon, category, triggers} overrides the derived metadata.
 */
class OpenApiImporter
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public static function isOpenApi(array $document): bool
    {
        return isset($document['openapi'], $document['paths']) && str_starts_with((string) $document['openapi'], '3.');
    }

    /**
     * OpenAPI descriptions are CommonMark. Keep them as written (the UI renders the Markdown as text, so "<key>"
     * or "<img>" stay visible instead of being stripped) and shorten long ones at a word boundary.
     */
    private static function text(mixed $value, int $limit): string
    {
        return Str::limit(trim(is_string($value) ? $value : ''), $limit, '…', preserveWords: true);
    }

    public function convert(array $spec): array
    {
        $meta = $spec['x-flowforge'] ?? [];
        $title = $spec['info']['title'] ?? 'OpenAPI Import';

        return [
            'key' => $meta['key'] ?? Str::slug($title, '_'),
            'name' => $meta['name'] ?? $title,
            'icon' => $meta['icon'] ?? 'globe',
            'category' => $meta['category'] ?? 'Imported',
            'description' => self::text($spec['info']['description'] ?? '', 1500),
            'base_url' => rtrim($spec['servers'][0]['url'] ?? '', '/'),
            'auth' => $this->auth($spec),
            'actions' => $this->actions($spec),
            'triggers' => $meta['triggers'] ?? [],
            'source' => 'openapi',
        ];
    }

    private function actions(array $spec): array
    {
        $actions = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $item = $this->deref($spec, $item);

            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }

                $operation = $item[$method];
                $endpoint = $path;
                $inputs = $query = $headers = [];

                $parameters = array_merge($item['parameters'] ?? [], $operation['parameters'] ?? []);
                foreach ($parameters as $parameter) {
                    $parameter = $this->deref($spec, $parameter);
                    $name = $parameter['name'] ?? null;
                    if (! $name || ! in_array($parameter['in'] ?? '', ['path', 'query', 'header'], true)) {
                        continue;
                    }

                    $key = $this->inputKey($name);
                    $placeholder = '{{ inputs.'.$key.' }}';
                    match ($parameter['in']) {
                        'path' => $endpoint = str_replace('{'.$name.'}', $placeholder, $endpoint),
                        'query' => $query[$name] = $placeholder,
                        'header' => $headers[$name] = $placeholder,
                    };

                    $inputs[$key] = $this->input($key, $this->deref($spec, $parameter['schema'] ?? []), [
                        'label' => Str::headline($name),
                        'required' => ($parameter['in'] === 'path') || (bool) ($parameter['required'] ?? false),
                        'help' => self::text($parameter['description'] ?? '', 400),
                    ]);
                }

                [$body, $bodyFormat, $bodyInputs] = $this->requestBody($spec, $operation['requestBody'] ?? null, $inputs);
                $inputs += $bodyInputs;

                $actions[] = array_filter([
                    'key' => $this->uniqueKey($operation['operationId'] ?? $method.' '.$path, $actions),
                    'name' => $operation['summary'] ?? Str::headline($operation['operationId'] ?? strtoupper($method).' '.$path),
                    'description' => self::text($operation['description'] ?? '', 1500),
                    'method' => strtoupper($method),
                    'endpoint' => $endpoint,
                    'query' => $query ?: null,
                    'headers' => $headers ?: null,
                    'body' => $body,
                    'body_format' => $bodyFormat,
                    'inputs' => array_values($inputs),
                ], fn ($value) => $value !== null);
            }
        }

        return $actions;
    }

    /**
     * @return array{0: mixed, 1: ?string, 2: array}
     */
    private function requestBody(array $spec, ?array $requestBody, array $existing): array
    {
        $requestBody = $requestBody ? $this->deref($spec, $requestBody) : null;
        $content = $requestBody['content'] ?? [];
        $format = isset($content['application/json']) ? 'json' : (isset($content['application/x-www-form-urlencoded']) ? 'form' : null);

        if (! $format) {
            return [null, null, []];
        }

        $schema = $this->deref($spec, $content[$format === 'json' ? 'application/json' : 'application/x-www-form-urlencoded']['schema'] ?? []);
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties) || $properties === []) {
            $input = $this->input('body', [], ['type' => 'json', 'label' => 'Body', 'required' => (bool) ($requestBody['required'] ?? false)]);

            return ['{{ inputs.body }}', $format, ['body' => $input]];
        }

        $body = $inputs = [];
        foreach ($properties as $name => $property) {
            $key = $this->inputKey(isset($existing[$this->inputKey($name)]) ? 'body_'.$name : $name);
            $body[$name] = '{{ inputs.'.$key.' }}';
            $property = $this->deref($spec, $property);
            if (! empty($property['readOnly'])) {
                unset($body[$name]);

                continue;
            }
            $inputs[$key] = $this->input($key, $property, [
                'label' => Str::headline($name),
                'required' => in_array($name, $schema['required'] ?? [], true),
                'help' => self::text($property['description'] ?? '', 400),
            ]);
        }

        return [$body, $format, $inputs];
    }

    private function input(string $key, array $schema, array $overrides): array
    {
        $type = match ($schema['type'] ?? 'string') {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'object', 'array' => 'json',
            default => 'string',
        };

        $input = ['key' => $key, 'type' => $type];

        if (! empty($schema['enum']) && $type === 'string') {
            $input['type'] = 'select';
            $input['options'] = array_map(fn ($value) => ['value' => (string) $value, 'label' => (string) $value], $schema['enum']);
        }
        if (array_key_exists('default', $schema) && ! is_array($schema['default'])) {
            $input['default'] = $schema['default'];
        }
        if (isset($schema['example']) && is_scalar($schema['example'])) {
            $input['placeholder'] = (string) $schema['example'];
        }

        return array_filter(array_merge($input, $overrides), fn ($value) => $value !== '' && $value !== null);
    }

    private function auth(array $spec): array
    {
        $auth = [];

        foreach ($spec['components']['securitySchemes'] ?? [] as $scheme) {
            $scheme = $this->deref($spec, $scheme);
            $type = $scheme['type'] ?? null;
            $httpScheme = strtolower($scheme['scheme'] ?? '');

            $auth[] = match (true) {
                $type === 'apiKey' && in_array($scheme['in'] ?? '', ['header', 'query'], true) => ['type' => 'api_key', 'in' => $scheme['in'], 'name' => $scheme['name']],
                $type === 'http' && $httpScheme === 'bearer' => ['type' => 'bearer'],
                $type === 'http' && $httpScheme === 'basic' => ['type' => 'basic'],
                $type === 'oauth2' && isset($scheme['flows']['authorizationCode']) => [
                    'type' => 'oauth2',
                    'authorize_url' => $scheme['flows']['authorizationCode']['authorizationUrl'],
                    'token_url' => $scheme['flows']['authorizationCode']['tokenUrl'],
                    'scopes' => array_keys($scheme['flows']['authorizationCode']['scopes'] ?? []),
                ],
                default => null,
            };
        }

        $auth = array_values(array_unique(array_filter($auth), SORT_REGULAR));

        return $auth ?: [['type' => 'none']];
    }

    private function deref(array $spec, mixed $node, int $depth = 0): array
    {
        if (! is_array($node)) {
            return [];
        }
        if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/') && $depth < 10) {
            $path = array_map(fn ($part) => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($node['$ref'], 2)));
            $target = $spec;
            foreach ($path as $part) {
                $target = $target[$part] ?? null;
            }

            return $this->deref($spec, $target, $depth + 1);
        }

        return $node;
    }

    private function inputKey(string $name): string
    {
        return trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $name), '_') ?: 'value';
    }

    private function uniqueKey(string $raw, array $actions): string
    {
        $key = Str::snake(preg_replace('/[^a-zA-Z0-9]+/', ' ', $raw));
        $key = trim(preg_replace('/_+/', '_', str_replace(' ', '_', $key)), '_') ?: 'operation';
        $taken = array_column($actions, 'key');
        $candidate = $key;
        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = $key.'_'.$i;
        }

        return $candidate;
    }
}
