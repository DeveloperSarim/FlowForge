<?php

/**
 * Switches FlowForge to an unpacked update (Settings → Updates). It runs outside Laravel so app/ and
 * vendor/ can be swapped without loading a mix of old and new classes, and it only acts on the
 * one-time token the app wrote to storage/app/updates/pending.json (valid 15 minutes).
 * Every replaced path is moved to a backup folder first; if any move fails, everything is put back.
 *
 * CLI (tests): php public/update-runner.php <token> <root>
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
$root = $cli ? (string) ($argv[2] ?? dirname(__DIR__)) : dirname(__DIR__);
$token = $cli ? (string) ($argv[1] ?? '') : (string) ($_POST['token'] ?? '');
$pendingFile = $root.'/storage/app/updates/pending.json';

function reply(int $status, array $body): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit($status === 200 ? 0 : 1);
}

if (! $cli && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['message' => 'Not found.']);
}

$pending = is_file($pendingFile) ? json_decode((string) file_get_contents($pendingFile), true) : null;
if (! is_array($pending) || $token === '' || ! hash_equals((string) ($pending['token'] ?? ''), $token) || (int) ($pending['expires'] ?? 0) < time()) {
    reply(403, ['message' => 'This update step has expired. Start the update again from Settings.']);
}
@unlink($pendingFile); // single use

$base = $root.'/storage/app/updates/';
$staging = (string) ($pending['staging'] ?? '');
$backup = (string) ($pending['backup'] ?? '');
if (! str_starts_with($staging, $base) || ! str_starts_with($backup, $base) || str_contains($staging.$backup, '..')) {
    reply(400, ['message' => 'The update plan is invalid.']);
}

$moved = [];
$undo = function () use (&$moved, $root, $staging, $backup): void {
    foreach (array_reverse($moved) as [$path, $hadOld]) {
        @rename("$root/$path", "$staging/$path");
        if ($hadOld) {
            @rename("$backup/$path", "$root/$path");
        }
    }
};

foreach ((array) ($pending['paths'] ?? []) as $path) {
    $path = (string) $path;
    if ($path === '' || $path[0] === '/' || str_contains($path, '..') || ! file_exists("$staging/$path")) {
        continue;
    }
    $live = "$root/$path";
    $hadOld = file_exists($live) || is_link($live);
    if (! is_dir(dirname("$backup/$path"))) {
        mkdir(dirname("$backup/$path"), 0755, true);
    }
    if ($hadOld && ! @rename($live, "$backup/$path")) {
        $undo();
        reply(500, ['message' => "Could not move {$path} aside (check file permissions). Nothing was changed."]);
    }
    if (! @rename("$staging/$path", $live)) {
        if ($hadOld) {
            @rename("$backup/$path", $live);
        }
        $undo();
        reply(500, ['message' => "Could not put the new {$path} in place. The previous version was restored."]);
    }
    $moved[] = [$path, $hadOld];
}

// These caches describe the old code (package providers, config, routes, the app catalog).
foreach ([...(glob($root.'/bootstrap/cache/*.php') ?: []), ...(glob($root.'/bootstrap/cache/integrations/*') ?: [])] as $cached) {
    @unlink($cached);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

reply(200, ['ok' => true, 'version' => $pending['version'] ?? null, 'replaced' => count($moved)]);
