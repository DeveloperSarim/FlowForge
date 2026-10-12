<?php

namespace App\Domain\System;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * In-app updates from an uploaded flowforge-update-x.y.z.zip:
 *   1. receiveChunk: the zip arrives in small chunks (stays under any upload_max_filesize) and is checked;
 *   2. extract: unpacked in batches into storage/app/updates/staging;
 *   3. public/update-runner.php (outside Laravel, one-time token) swaps the staged code in and keeps a backup;
 *   4. finish: runs on the new code: migrations, caches, history.
 * .env, storage/ and bootstrap/cache are never in an update package, so settings and data stay.
 */
class Updater
{
    public const MANIFEST = 'flowforge-update.json';

    private const BATCH = 1500;

    private const MAX_BYTES = 250 * 1024 * 1024;

    public function root(): string
    {
        return rtrim(config('flowforge.update_root') ?: base_path(), '/');
    }

    public function current(): string
    {
        $file = $this->root().'/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : '1.0.0';
    }

    private function dir(string $path = ''): string
    {
        return $this->root().'/storage/app/updates'.($path !== '' ? '/'.$path : '');
    }

    /** Appends one chunk. Returns the package details after the last chunk, null before that. */
    public function receiveChunk(string $uploadId, int $index, int $total, int $offset, UploadedFile $chunk): ?array
    {
        $part = $this->dir("uploads/{$uploadId}.part");
        File::ensureDirectoryExists(dirname($part));
        if ($index === 0) {
            File::delete(File::glob($this->dir('uploads/*.part')));
        }
        clearstatcache();
        if ((is_file($part) ? filesize($part) : 0) !== $offset) {
            throw new RuntimeException('The upload got out of step. Choose the file again.');
        }
        if ($offset + $chunk->getSize() > self::MAX_BYTES) {
            File::delete($part);
            throw new RuntimeException('That file is too large to be a FlowForge update.');
        }
        file_put_contents($part, file_get_contents($chunk->getRealPath()), FILE_APPEND | LOCK_EX);

        if ($index < $total - 1) {
            return null;
        }

        $package = $this->dir('incoming/package.zip');
        File::ensureDirectoryExists(dirname($package));
        File::move($part, $package);

        try {
            return $this->inspect($package);
        } catch (RuntimeException $e) {
            File::delete($package);
            throw $e;
        }
    }

    /** Checks the zip is an update package for a newer version that this server can run. */
    public function inspect(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP "zip" extension is off. Turn it on in your hosting panel to install updates.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file is not a valid zip. Upload it again.');
        }

        $manifest = json_decode((string) $zip->getFromName(self::MANIFEST), true);
        if (! is_array($manifest) || ($manifest['product'] ?? null) !== 'flowforge') {
            throw new RuntimeException($zip->locateName('artisan') !== false
                ? 'This is the fresh-install zip. Upload the update zip instead (flowforge-update-….zip).'
                : 'This is not a FlowForge update package.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_contains($name, '..') || $name[0] === '/' || str_starts_with($name, 'storage/') || $name === '.env') {
                throw new RuntimeException("The package contains a file it must not touch ({$name}).");
            }
        }

        $version = (string) ($manifest['version'] ?? '');
        $current = $this->current();
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version) || $zip->locateName('vendor/autoload.php') === false) {
            throw new RuntimeException('The package is incomplete. Build it again with scripts/make-release.sh.');
        }
        if (! version_compare($version, $current, '>')) {
            throw new RuntimeException("This server already runs {$current}; the package is {$version}. Upload a newer version.");
        }
        if (version_compare(PHP_VERSION, (string) ($manifest['php'] ?? '8.2.0'), '<')) {
            throw new RuntimeException("Version {$version} needs PHP {$manifest['php']} or newer; this server runs ".PHP_VERSION.'. Switch the PHP version in your hosting panel first.');
        }

        return ['version' => $version, 'current' => $current, 'files' => $zip->numFiles, 'built_at' => $manifest['built_at'] ?? null];
    }

    /**
     * Unpacks the next batch into staging. After the last batch it writes the one-time swap plan and
     * returns its token for public/update-runner.php.
     *
     * @return array{done: bool, next: int, total: int, token?: string, url?: string}
     */
    public function extract(int $from): array
    {
        $package = $this->dir('incoming/package.zip');
        $info = $this->inspect($package);
        $staging = $this->dir('staging');
        if ($from === 0) {
            File::deleteDirectory($staging);
        }
        File::ensureDirectoryExists($staging);

        $zip = new ZipArchive;
        $zip->open($package);
        $total = $zip->numFiles;
        $to = min($total, $from + self::BATCH);
        $names = array_map(fn ($i) => (string) $zip->getNameIndex($i), $to > $from ? range($from, $to - 1) : []);
        if ($names !== [] && ! $zip->extractTo($staging, $names)) {
            throw new RuntimeException('Unpacking failed: '.$zip->getStatusString().'. Check the free disk space.');
        }
        $zip->close();

        if ($to < $total) {
            return ['done' => false, 'next' => $to, 'total' => $total];
        }

        $token = Str::random(48);
        File::put($this->dir('pending.json'), json_encode([
            'token' => $token,
            'expires' => time() + 900,
            'version' => $info['version'],
            'staging' => $staging,
            'backup' => $this->dir('backup-'.$info['current'].'-'.date('YmdHis')),
            'paths' => $this->swapPaths($staging),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($this->dir('last.json'), json_encode(['from' => $info['current'], 'to' => $info['version']]));

        return ['done' => true, 'next' => $total, 'total' => $total, 'token' => $token];
    }

    /** What gets replaced: every top-level entry, but only the code inside public/ and bootstrap/. */
    private function swapPaths(string $staging): array
    {
        $paths = [];
        foreach (array_diff(scandir($staging), ['.', '..', self::MANIFEST, 'storage', '.env']) as $entry) {
            if (in_array($entry, ['public', 'bootstrap'], true)) {
                foreach (array_diff(scandir("$staging/$entry"), ['.', '..', 'storage', 'cache']) as $child) {
                    $paths[] = "$entry/$child";
                }
            } else {
                $paths[] = $entry;
            }
        }

        return array_values($paths);
    }

    /** Runs on the new code after the swap. */
    public function finish(SystemSettings $settings): array
    {
        $last = json_decode((string) @file_get_contents($this->dir('last.json')), true) ?: [];
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('view:clear');

        File::deleteDirectory($this->dir('staging'));
        File::deleteDirectory($this->dir('incoming'));
        File::deleteDirectory($this->dir('uploads'));
        foreach (SchemaUpgrader::OBSOLETE as $path) {
            File::delete($this->root().'/'.$path);
        }
        File::delete($this->dir('last.json'));
        // Keep the newest backup for a manual rollback; older ones only use disk space.
        $backups = File::glob($this->dir('backup-*'));
        usort($backups, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, 1) as $old) {
            File::deleteDirectory($old);
        }

        $entry = ['from' => $last['from'] ?? null, 'to' => $this->current(), 'at' => now()->toIso8601String()];
        $settings->set('update_history', array_slice([$entry, ...(array) $settings->get('update_history', [])], 0, 10));

        return $entry;
    }
}
