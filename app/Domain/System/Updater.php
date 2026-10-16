<?php

namespace App\Domain\System;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * In-app updates from flowforge-update-x.y.z.zip, either downloaded from the latest GitHub release or uploaded:
 *   1. download (from GitHub; SHA256SUMS.txt must be signed with the release key and match the zip) or receiveChunk
 *      (uploaded in small chunks, under any upload_max_filesize) puts the zip in place, and it is checked;
 *   2. extract: unpacked in batches into storage/app/updates/staging;
 *   3. public/update-runner.php (outside Laravel, one-time token) swaps the staged code in and keeps a backup;
 *   4. finish: runs on the new code: migrations, caches, history.
 * .env, storage/ and bootstrap/cache are never in an update package, so settings and data stay.
 */
class Updater
{
    public const MANIFEST = 'flowforge-update.json';

    public const LATEST = 'flowforge:latest-release';

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

    /**
     * Appends one chunk. Returns the package details after the last chunk, null before that.
     * Uploads skip the release signature on purpose: an admin installing their own zip ("Install from a zip
     * file instead") is trusted with it, like uploading code over FTP. Only one-click downloads are verified.
     */
    public function receiveChunk(string $uploadId, int $index, int $total, int $offset, UploadedFile $chunk): ?array
    {
        $part = $this->dir("uploads/{$uploadId}.part");
        File::ensureDirectoryExists(dirname($part));
        if ($index === 0) {
            // Start this upload afresh; another upload's part stays unless it was abandoned (older than 12 hours).
            File::delete(array_filter(File::glob($this->dir('uploads/*.part')), fn ($p) => $p === $part || @filemtime($p) < time() - 12 * 3600));
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

    /**
     * The newest release on GitHub, cached for 6 hours (the settings page and the daily check share it).
     *
     * @return array{version: string, notes: ?string, published_at: ?string, url: string, zip: ?string, sums: ?string, sig: ?string, checked_at: string}
     */
    public function latest(bool $fresh = false): array
    {
        if (! $fresh && is_array($cached = Cache::get(self::LATEST))) {
            return $cached;
        }
        $repo = config('flowforge.updates.repo');
        $http = fn () => Http::timeout(15)->withHeaders(['User-Agent' => 'FlowForge/'.$this->current()]);
        $release = null;
        try {
            $api = $http()->accept('application/vnd.github+json')->get("https://api.github.com/repos/{$repo}/releases/latest");
            if ($api->ok() && is_string($api->json('tag_name'))) {
                $version = ltrim($api->json('tag_name'), 'v');
                $assets = collect($api->json('assets', []))->pluck('browser_download_url', 'name');
                $release = ['version' => $version, 'notes' => Str::limit(trim((string) $api->json('body')), 4000) ?: null, 'published_at' => $api->json('published_at'),
                    'url' => $api->json('html_url'), 'zip' => $assets["flowforge-update-{$version}.zip"] ?? null, 'sums' => $assets['SHA256SUMS.txt'] ?? null,
                    'sig' => $assets['SHA256SUMS.txt.sig'] ?? null];
            }
        } catch (ConnectionException) {
        }
        if (! $release) {
            // The API allows 60 calls an hour per IP, which a shared host can use up; the release page redirect has no such limit.
            try {
                $location = (string) $http()->withoutRedirecting()->get("https://github.com/{$repo}/releases/latest")->header('Location');
            } catch (ConnectionException) {
                $location = '';
            }
            if (! preg_match('#/releases/tag/(v?(\d+\.\d+\.\d+))$#', $location, $m)) {
                throw new RuntimeException("Couldn't reach GitHub to check for updates. Try again in a few minutes, or upload the update zip below.");
            }
            $base = "https://github.com/{$repo}/releases/download/{$m[1]}";
            $release = ['version' => $m[2], 'notes' => null, 'published_at' => null, 'url' => "https://github.com/{$repo}/releases/tag/{$m[1]}",
                'zip' => "{$base}/flowforge-update-{$m[2]}.zip", 'sums' => "{$base}/SHA256SUMS.txt", 'sig' => "{$base}/SHA256SUMS.txt.sig"];
        }
        $release['checked_at'] = now()->toIso8601String();
        Cache::put(self::LATEST, $release, now()->addHours(6));

        return $release;
    }

    /** A newer version than this one, from the last check (no network call), or null. */
    public function availableFromCache(): ?string
    {
        $latest = Cache::get(self::LATEST);

        return is_array($latest) && ! empty($latest['zip']) && version_compare($latest['version'], $this->current(), '>') ? $latest['version'] : null;
    }

    /**
     * Downloads the latest release's update zip from GitHub and checks it like an upload, but only after proving it is
     * an official release: SHA256SUMS.txt must carry a valid Ed25519 signature (SHA256SUMS.txt.sig, made by
     * scripts/sign-release.php) from config('flowforge.updates.public_key') and list the zip with its exact SHA-256.
     * Anything missing or wrong refuses the update and nothing is kept.
     */
    public function download(): array
    {
        $release = $this->latest(true);
        if (! version_compare($release['version'], $this->current(), '>')) {
            throw new RuntimeException("You're already on the latest version ({$this->current()}).");
        }
        if (! $release['zip']) {
            throw new RuntimeException("Version {$release['version']} has no update package on GitHub yet. Upload the zip below instead.");
        }

        $package = $this->dir('incoming/package.zip');
        File::ensureDirectoryExists(dirname($package));
        File::delete($package);
        $fail = function (string $message) use ($package) {
            File::delete($package);
            throw new RuntimeException($message);
        };

        $version = $release['version'];
        $fetch = fn (?string $url) => $url ? rescue(fn () => Http::timeout(15)->withHeaders(['User-Agent' => 'FlowForge/'.$this->current()])->get($url), null, false) : null;
        $sums = $fetch($release['sums'] ?? null);
        $sig = $fetch($release['sig'] ?? null);
        if (! $sums?->successful() || ! $sig?->successful()) {
            $fail("Couldn't get the signature of version {$version} from GitHub, so nothing was installed. Try again in a few minutes.");
        }
        $signed = rescue(fn () => sodium_crypto_sign_verify_detached((string) base64_decode(trim($sig->body()), true), $sums->body(),
            (string) base64_decode((string) config('flowforge.updates.public_key'), true)), false, false);
        if (! $signed) {
            $fail("Version {$version} on GitHub isn't signed with the FlowForge release key, so it was not installed. It may have been tampered with.");
        }
        if (! preg_match('/^([a-f0-9]{64})\s+\*?'.preg_quote("flowforge-update-{$version}.zip", '/').'\s*$/mi', $sums->body(), $sum)) {
            $fail("The signed checksum list of version {$version} doesn't include its update package, so it was not installed.");
        }

        try {
            $response = Http::timeout(600)->withHeaders(['User-Agent' => 'FlowForge/'.$this->current()])->sink($package)->get($release['zip']);
        } catch (ConnectionException) {
            $fail('The download from GitHub was interrupted. Try again, or upload the zip below.');
        }
        if (! $response->successful()) {
            $fail("GitHub answered HTTP {$response->status()} for the update package. Try again later, or upload the zip below.");
        }
        clearstatcache();
        if (! is_file($package) || filesize($package) > self::MAX_BYTES) {
            $fail('The downloaded file is not a FlowForge update.');
        }
        // A mismatch means a broken or altered download.
        if (! hash_equals(strtolower($sum[1]), hash_file('sha256', $package))) {
            $fail('The downloaded package failed its checksum check, so it was not installed. Try again.');
        }

        try {
            return $this->inspect($package);
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
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
