<?php

namespace App\Domain\System;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Keeps the database in step with the code on disk. When the VERSION file differs from the version the
 * schema was last migrated for, pending migrations run once (other requests wait on a file lock) and
 * files older versions shipped are removed. Called on every web/API request and every cron tick, so an
 * update completes itself however the files arrived (Settings → Updates, the unpacker, a manual upload),
 * even when a request that should have finished it never happened.
 */
class SchemaUpgrader
{
    /** Files earlier versions shipped that would now shadow a route. */
    public const OBSOLETE = ['public/robots.txt'];

    private const RETRY_AFTER_SECONDS = 60;

    public static function ensureCurrent(): void
    {
        $version = trim((string) @file_get_contents(base_path('VERSION'))) ?: '0';
        $marker = config('flowforge.schema_marker') ?: storage_path('framework/schema-version');
        if (@file_get_contents($marker) === $version) {
            return; // the normal case: one small file read per request
        }

        $failed = $marker.'.failed';
        if (is_file($failed) && time() - (int) @filemtime($failed) < self::RETRY_AFTER_SECONDS) {
            return; // a migration just failed; don't retry it on every request
        }

        $lock = @fopen($marker.'.lock', 'c');
        if (! $lock) {
            return;
        }
        try {
            flock($lock, LOCK_EX); // concurrent requests wait here, then see the fresh marker
            clearstatcache();
            if (@file_get_contents($marker) === $version) {
                return;
            }
            Artisan::call('migrate', ['--force' => true]);
            foreach (self::OBSOLETE as $path) {
                @unlink(base_path($path));
            }
            file_put_contents($marker, $version);
            @unlink($failed);
        } catch (Throwable $e) {
            report($e);
            @touch($failed);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
