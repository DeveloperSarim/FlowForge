<?php

/**
 * FlowForge unpacker for shared hosting, for when the hosting File Manager times out on the release
 * zip (~14,000 files). Upload this file next to flowforge-*.zip (e.g. both into public_html) and open
 * https://your-domain/extract-flowforge.php. It extracts in batches (each request stays short), then
 * deletes the zip and itself and opens the installer.
 */
declare(strict_types=1);

const BATCH = 1500;

$dir = __DIR__;
$zips = glob($dir.'/flowforge-*.zip') ?: [];
rsort($zips); // date-stamped names: newest first
$zipPath = $zips[0] ?? null;
$from = max(0, (int) ($_GET['from'] ?? 0));

function page(string $title, string $body, ?string $next = null, int $percent = -1): never
{
    $refresh = $next !== null ? '<meta http-equiv="refresh" content="0;url='.htmlspecialchars($next).'">' : '';
    $bar = $percent >= 0 ? '<div class="bar"><span style="width:'.$percent.'%"></span></div><p class="muted">'.$percent.'%</p>' : '';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.$refresh
        .'<title>'.htmlspecialchars($title).'</title><style>body{font:15px/1.5 system-ui,sans-serif;background:#f6f7f9;color:#1b1f24;display:grid;place-items:center;min-height:100vh;margin:0}'
        .'main{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:28px 32px;max-width:460px;width:calc(100% - 32px)}h1{font-size:19px;margin:0 0 6px}'
        .'.muted{color:#555e6c;font-size:13px}.bar{height:8px;background:#eef0f3;border-radius:99px;overflow:hidden;margin-top:16px}.bar span{display:block;height:100%;background:#ee5a24}'
        .'a{color:#ee5a24;font-weight:600}</style></head><body><main><h1>'.htmlspecialchars($title).'</h1><p>'.$body.'</p>'.$bar.'</main></body></html>';
    exit;
}

if (is_file($dir.'/storage/.installed')) {
    page('Already installed', 'FlowForge is already installed in this folder. Delete <code>extract-flowforge.php</code> from your File Manager.');
}
if (! class_exists(ZipArchive::class)) {
    page('PHP "zip" extension is off', 'Turn it on in your hosting panel (Hostinger: Advanced → PHP Configuration → PHP extensions → zip), then reload this page.');
}
if ($zipPath === null) {
    page('Zip not found', 'Upload <code>flowforge-….zip</code> into the same folder as this file, then reload this page.');
}

$zip = new ZipArchive;
if ($zip->open($zipPath) !== true) {
    page('Could not open the zip', 'The upload may be incomplete. Upload <code>'.htmlspecialchars(basename($zipPath)).'</code> again, then reload this page.');
}

$total = $zip->numFiles;
$to = min($total, $from + BATCH);
$names = [];
for ($i = $from; $i < $to; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if ($name !== '' && ! str_contains($name, '..') && $name[0] !== '/') { // never write outside this folder
        $names[] = $name;
    }
}

if ($names !== [] && ! $zip->extractTo($dir, $names)) {
    page('Extraction failed', 'At file '.$from.' of '.$total.': '.htmlspecialchars($zip->getStatusString()).'. Check free disk space and folder permissions, then reload this page.');
}
$zip->close();

if ($to < $total) {
    page('Unpacking FlowForge…', 'Extracted '.$to.' of '.$total.' files. Keep this tab open.', '?from='.$to, (int) floor($to / $total * 100));
}

@unlink($zipPath);
@unlink(__FILE__);
page('FlowForge is unpacked', 'All '.$total.' files are in place. The zip and this unpacker were deleted. <a href="install">Open the installer</a>.', 'install', 100);
