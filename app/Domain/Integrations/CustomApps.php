<?php

namespace App\Domain\Integrations;

use App\Domain\Workflows\Models\Workflow;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Apps an install adds itself: built by the AI builder or an MCP agent when the catalog has no app for a
 * service, or imported from an OpenAPI document. Each one is an ordinary manifest file in
 * storage/app/integrations, so the loader, executor and the AI's checks treat it like a bundled app,
 * and updates (which never touch storage/) keep it.
 */
class CustomApps
{
    public const CATEGORY = 'Custom apps';

    public const PREFIX = 'custom_';

    public function __construct(private ManifestLoader $loader, private IntegrationManifestSchema $schema, private OpenApiImporter $openApi) {}

    /**
     * Creates or replaces a custom app. The key is always "custom_<slug>", so a custom app can't shadow a
     * bundled one, and every action is pinned to the app's base URL so its credential never leaves that host.
     *
     * @return array{manifest: array, created: bool}
     */
    public function save(array $spec, int $maxActions = 60): array
    {
        $name = trim((string) ($spec['name'] ?? ''));
        $slug = Str::of((string) ($spec['key'] ?? $name))->after(self::PREFIX)->slug('_')->limit(60, '')->trim('_');
        if ($name === '' || $slug->isEmpty()) {
            throw new InvalidArgumentException('Give the app a name.');
        }
        $key = self::PREFIX.$slug;
        $baseUrl = trim((string) ($spec['base_url'] ?? ''));
        if (! preg_match('#^https?://[^/\s]+#i', $baseUrl)) {
            throw new InvalidArgumentException('base_url must be the API\'s absolute URL, e.g. https://api.example.com/v1 (a {{ credentials.<field> }} part is allowed).');
        }

        $actions = array_values((array) ($spec['actions'] ?? []));
        if ($actions === [] || count($actions) > $maxActions) {
            throw new InvalidArgumentException("A custom app needs 1 to {$maxActions} actions.");
        }
        foreach ($actions as $i => $action) {
            $endpoint = (string) ($action['endpoint'] ?? '');
            if (isset($action['handler']) || preg_match('#^[a-z]+://#i', $endpoint)) {
                throw new InvalidArgumentException("actions.{$i}: endpoint must be a path on the base URL (e.g. /contacts), not a full URL or a handler.");
            }
            $actions[$i] = ['restrict_to_base_url' => true] + $action;
        }
        $triggers = array_values(array_map(function ($trigger) {
            $trigger = (array) $trigger + ['type' => 'polling', 'method' => 'GET', 'id_field' => 'id'];
            if ($trigger['type'] === 'polling' && ! collect($trigger['inputs'] ?? [])->contains('key', 'poll_interval')) {
                $trigger['inputs'][] = ['key' => 'poll_interval', 'label' => 'Check every (minutes)', 'type' => 'number', 'default' => 5];
            }

            return $trigger;
        }, (array) ($spec['triggers'] ?? [])));
        if (collect($triggers)->contains(fn ($t) => ! in_array($t['type'], ['polling', 'webhook'], true))) {
            throw new InvalidArgumentException('Custom app triggers are polling (an endpoint that lists newest items first) or webhook.');
        }

        $manifest = array_filter([
            'key' => $key,
            'name' => Str::limit($name, 120, ''),
            'category' => self::CATEGORY,
            'description' => Str::limit(trim((string) ($spec['description'] ?? '')), 1500, '…', preserveWords: true),
            'website' => $spec['website'] ?? null,
            'docs_url' => $spec['docs_url'] ?? null,
            'base_url' => rtrim($baseUrl, '/'),
            'headers' => $spec['headers'] ?? null,
            'auth' => $spec['auth'] ?? ['type' => 'none'],
            'error_hints' => $spec['error_hints'] ?? null,
            'actions' => $actions,
            'triggers' => $triggers,
            'source' => $spec['source'] ?? 'custom',
            'openapi_url' => $spec['openapi_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $manifest['triggers'] ??= [];

        $normalized = $this->schema->normalize($manifest);
        $errors = $this->schema->validate($normalized);
        $credentialTypes = ['none', 'api_key', 'bearer', 'basic', 'oauth2', 'oauth2_client_credentials'];
        foreach ($normalized['auth'] as $auth) {
            if (! in_array($auth['type'] ?? null, $credentialTypes, true)) {
                $errors[] = 'auth.type must be one of: '.implode(', ', $credentialTypes).'.';
            }
        }
        if ($errors) {
            throw new InvalidArgumentException('The app isn\'t valid: '.implode(' ', array_unique($errors)));
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 512_000) {
            throw new InvalidArgumentException('The app is too large (over 500 KB). Keep the actions you need.');
        }
        $file = $this->path($key);
        $created = ! is_file($file);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $json."\n");
        $this->loader->flush();

        return ['manifest' => $this->loader->get($key) ?? $normalized, 'created' => $created];
    }

    /**
     * Builds a custom app from an OpenAPI (Swagger 3) document: a URL to fetch, or the document itself.
     *
     * @return array{manifest: array, created: bool}
     */
    public function importOpenApi(string $source, ?string $name = null): array
    {
        $source = trim($source);
        $url = preg_match('#^https?://#i', $source) ? $source : null;
        if ($url) {
            try {
                $response = Http::timeout(25)->accept('application/json, application/yaml, text/yaml, */*')->get($url);
            } catch (Throwable $e) {
                throw new InvalidArgumentException("Couldn't download the OpenAPI document: {$e->getMessage()}");
            }
            if (! $response->successful()) {
                throw new InvalidArgumentException("Couldn't download the OpenAPI document (HTTP {$response->status()}).");
            }
            $source = $response->body();
        }
        if (strlen($source) > 8_000_000) {
            throw new InvalidArgumentException('The OpenAPI document is over 8 MB.');
        }

        try {
            $document = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $document = rescue(fn () => Yaml::parse($source), null, false);
        }
        if (! is_array($document) || ! OpenApiImporter::isOpenApi($document)) {
            throw new InvalidArgumentException('That isn\'t an OpenAPI 3 document (it needs "openapi": "3.x" and "paths").');
        }

        $converted = $this->openApi->convert($document);
        // A relative server ("/v1") is relative to where the document came from.
        if ($url && ! preg_match('#^https?://#i', $converted['base_url'] ?? '')) {
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : '');
            $converted['base_url'] = $origin.'/'.ltrim((string) ($converted['base_url'] ?? ''), '/');
        }

        return $this->save(['name' => $name ?: $converted['name'], 'source' => 'openapi', 'openapi_url' => $url] + $converted, 300);
    }

    /** Workflows that use the app (it can't be deleted while any do). */
    public function usedBy(string $key): array
    {
        return Workflow::query()->get(['id', 'name', 'graph'])
            ->filter(fn (Workflow $w) => collect($w->graph['nodes'] ?? [])->contains(fn ($n) => ($n['data']['integration'] ?? null) === $key))
            ->map(fn (Workflow $w) => ['id' => $w->id, 'name' => $w->name])->values()->all();
    }

    public function delete(string $key): void
    {
        if (! $this->isCustom($key)) {
            throw new InvalidArgumentException('Only custom apps can be deleted.');
        }
        if ($used = $this->usedBy($key)) {
            throw new InvalidArgumentException('It is used by '.collect($used)->pluck('name')->map(fn ($n) => "\"{$n}\"")->implode(', ').'. Remove it from those workflows first.');
        }
        File::delete($this->path($key));
        $this->loader->flush();
    }

    /** The app as it was saved (no generated actions), for exports that carry it to another install. */
    public function raw(string $key): ?array
    {
        return $this->isCustom($key) ? json_decode((string) file_get_contents($this->path($key)), true) : null;
    }

    public function isCustom(string $key): bool
    {
        return str_starts_with($key, self::PREFIX) && is_file($this->path($key));
    }

    private function path(string $key): string
    {
        return rtrim((string) config('flowforge.custom_integrations_path'), '/').'/'.basename($key).'.json';
    }
}
