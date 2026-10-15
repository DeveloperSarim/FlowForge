<?php

namespace App\Domain\Integrations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads every manifest (.json/.yaml/.yml, recursively) under resources/integrations, plus raw
 * OpenAPI v3 documents dropped in the same tree, plus the install's custom apps (storage/app/integrations,
 * see CustomApps), which are marked `custom`.
 *
 * Built for 500+ integrations: the compiled result is split into bootstrap/cache/integrations/
 * index.php (lightweight catalog) + one file per integration, so a request only loads the index and
 * the manifests it actually touches. Everything is rebuilt when any manifest or icon changes.
 */
class ManifestLoader
{
    private ?array $index = null;

    /** @var array<string, array|null> */
    private array $loaded = [];

    public function __construct(
        private IntegrationManifestSchema $schema,
        private OpenApiImporter $openApi,
        private ?string $path = null,
        private ?string $cacheDir = null,
        private ?string $customPath = null,
    ) {
        $this->path ??= config('flowforge.integrations_path');
        $this->cacheDir ??= base_path('bootstrap/cache/integrations');
        $this->customPath ??= (string) config('flowforge.custom_integrations_path');
    }

    /**
     * Lightweight listing (no inputs/templates) for palettes, directories and pickers.
     *
     * @return list<array{key: string, name: string, icon: string, category: string, description: string, popular: bool, auth: list<string>, actions: list<array>, triggers: list<array>}>
     */
    public function catalog(): array
    {
        return $this->index()['catalog'];
    }

    /** @return array<string, array> catalog entries keyed by integration key */
    public function catalogMap(): array
    {
        return array_column($this->catalog(), null, 'key');
    }

    public function count(): int
    {
        return count($this->index()['catalog']);
    }

    /** @return array<string, list<string>> invalid files => errors */
    public function errors(): array
    {
        return $this->index()['errors'];
    }

    public function get(string $key): ?array
    {
        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $file = $this->index()['files'][$key] ?? null;
        if (! $file) {
            return $this->loaded[$key] = null;
        }

        // Self-heal if the compiled file vanished (e.g. cache cleared mid-request): rebuild once.
        if (! is_file($this->cacheDir.'/'.$file)) {
            @unlink($this->cacheDir.'/index.php');
            $this->index = null;
            $this->index();
            if (array_key_exists($key, $this->loaded)) {
                return $this->loaded[$key];
            }
        }

        return $this->loaded[$key] = rescue(fn () => require $this->cacheDir.'/'.$file, null, false);
    }

    public function action(string $integration, string $action): ?array
    {
        return collect($this->get($integration)['actions'] ?? [])->firstWhere('key', $action);
    }

    public function trigger(string $integration, string $trigger): ?array
    {
        return collect($this->get($integration)['triggers'] ?? [])->firstWhere('key', $trigger);
    }

    /** Force a rebuild on next access. Only the index is removed, so concurrent readers never lose files. */
    public function flush(): void
    {
        $this->index = null;
        $this->loaded = [];
        @unlink($this->cacheDir.'/index.php');
    }

    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $list = fn (string $dir) => $dir !== '' && is_dir($dir)
            ? collect(File::allFiles($dir))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['json', 'yaml', 'yml'], true))
                ->map(fn ($file) => $file->getPathname())
                ->sort()->values()->all()
            : [];
        $files = [...$list($this->path), ...$list($this->customPath)]; // bundled first: a custom app can't shadow one

        // The compiler's own source and the icon folder are part of the signature, so an upgrade or
        // a newly generated logo invalidates the cache too.
        $sources = [__FILE__, __DIR__.'/IntegrationManifestSchema.php', __DIR__.'/OpenApiImporter.php', ...$files];
        $iconDir = public_path('integrations/icons');
        $signature = md5(implode('|', array_map(fn ($file) => $file.':'.filemtime($file).':'.filesize($file), $sources))
            .(is_dir($iconDir) ? filemtime($iconDir) : ''));

        $indexFile = $this->cacheDir.'/index.php';
        if (is_file($indexFile)) {
            $cached = rescue(fn () => require $indexFile, null, false);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
                return $this->index = $cached;
            }
        }

        return $this->index = $this->build($files, $signature);
    }

    private function build(array $files, string $signature): array
    {
        $manifests = $errors = [];

        foreach ($files as $file) {
            $custom = $this->customPath !== '' && str_starts_with($file, rtrim($this->customPath, '/').'/');
            $relative = $custom ? 'custom/'.basename($file) : ltrim(str_replace($this->path, '', $file), '/');

            try {
                $document = str_ends_with(strtolower($file), '.json')
                    ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                    : Yaml::parseFile($file);

                if (! is_array($document)) {
                    throw new \UnexpectedValueException('Document is not an object.');
                }

                $manifest = $this->schema->normalize(
                    OpenApiImporter::isOpenApi($document) ? $this->openApi->convert($document) : $document
                );
                $manifest['custom'] = $custom;

                if ($problems = $this->schema->validate($manifest)) {
                    $errors[$relative] = $problems;

                    continue;
                }
                if (isset($manifests[$manifest['key']])) {
                    $errors[$relative] = ["Duplicate integration key [{$manifest['key']}]."];

                    continue;
                }

                $manifests[$manifest['key']] = $manifest;
            } catch (Throwable $e) {
                $errors[$relative] = [$e->getMessage()];
            }
        }

        foreach ($errors as $file => $problems) {
            Log::warning("Integration manifest [$file] skipped: ".implode(' ', $problems));
        }

        uasort($manifests, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $index = [
            'signature' => $signature,
            'catalog' => array_values(array_map(fn (array $m) => [
                'key' => $m['key'],
                'name' => $m['name'],
                'icon' => $m['icon'],
                'category' => $m['category'],
                'description' => $m['description'],
                'popular' => (bool) ($m['popular'] ?? false),
                'custom' => $m['custom'],
                'auth' => array_values(array_diff(array_column($m['auth'], 'type'), ['none'])),
                'auth_optional' => in_array('none', array_column($m['auth'], 'type'), true),
                'actions' => array_map(fn ($a) => ['key' => $a['key'], 'name' => $a['name'], 'branch' => ! empty($a['outputs'])], $m['actions']),
                'triggers' => array_map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'type' => $t['type']], $m['triggers']),
            ], $manifests)),
            'files' => array_map(fn (array $m) => 'm-'.$m['key'].'.php', $manifests),
            'errors' => $errors,
        ];

        File::ensureDirectoryExists($this->cacheDir);
        foreach ($manifests as $key => $manifest) {
            $this->writePhp($this->cacheDir.'/'.$index['files'][$key], $manifest);
        }
        $this->writePhp($this->cacheDir.'/index.php', $index); // last: the index only points at files that exist

        $this->loaded = $manifests;

        return $index;
    }

    private function writePhp(string $file, array $data): void
    {
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, '<?php return '.var_export($data, true).';'.PHP_EOL, LOCK_EX) !== false) {
            @rename($tmp, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
        @unlink($tmp);
    }
}
