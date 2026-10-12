<?php

/**
 * FlowForge license server (plain PHP 8.1+, no framework). Endpoints:
 *   POST /api/v1/activate   {purchase_code, domain, product, version} → signed token for that domain
 *   POST /api/v1/verify     {token, signature} → {status: active|revoked, token?, signature?}
 *   POST /api/v1/deactivate {token, signature} → frees the domain
 *   GET  /admin             activations list (release / revoke / restore)
 * One purchase code = one production domain; development hosts (localhost, *.test, *.local) are free.
 */
declare(strict_types=1);

ini_set('display_errors', '0'); // warnings go to the error log, never into a JSON answer

const DEV_HOST = '/^(localhost|127\.0\.0\.1|\[::1\]|::1|.+\.(test|local|localhost))$/';
const CODE_FORMAT = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

$config = require __DIR__.'/config.php';
$db = new PDO($config['dsn'], $config['db_user'] ?? null, $config['db_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
migrate($db);

$path = '/'.trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$path = preg_replace('#^.*?(/api/v1/|/admin)#', '$1', $path);

if (str_starts_with($path, '/admin')) {
    admin($db, $config);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(404, ['message' => 'Not found.']);
}
$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
throttle($db, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $path);

match ($path) {
    '/api/v1/activate' => activate($db, $config, $input),
    '/api/v1/verify' => verify($db, $config, $input),
    '/api/v1/deactivate' => deactivate($db, $config, $input),
    default => reply(404, ['message' => 'Not found.']),
};

function activate(PDO $db, array $config, array $in): never
{
    $code = trim((string) ($in['purchase_code'] ?? ''));
    $domain = normalize_host((string) ($in['domain'] ?? ''));
    if (($in['product'] ?? '') !== 'flowforge' || $domain === '' || ! preg_match('/^[a-z0-9.\-\[\]:]+$/', $domain)) {
        reply(422, ['message' => 'Invalid activation request.']);
    }

    // The author's live demo.
    if ($config['demo_key'] !== '' && hash_equals($config['demo_key'], $code)) {
        if (! in_array($domain, array_map('normalize_host', $config['demo_domains']), true)) {
            reply(403, ['message' => 'This demo key only works on the demo domains.']);
        }
        $id = upsert($db, hash('sha256', $code), 'DEMO', $domain, false, ['buyer' => 'demo', 'license' => 'Demo', 'supported_until' => null, 'item' => ['id' => 0]]);
        reply(200, sign($config, ['aid' => $id, 'domain' => $domain, 'type' => 'Demo', 'buyer' => 'demo', 'demo' => true]));
    }

    if (! preg_match(CODE_FORMAT, $code)) {
        reply(422, ['message' => 'That doesn\'t look like a purchase code. It has 36 characters, like 1a2b3c4d-1a2b-1a2b-1a2b-1a2b3c4d5e6f.']);
    }
    $sale = envato_sale($config, $code);
    $hash = hash('sha256', strtolower($code));
    $dev = (bool) preg_match(DEV_HOST, $domain);

    $revoked = $db->prepare("SELECT reason FROM activations WHERE code_hash = ? AND status = 'revoked' LIMIT 1");
    $revoked->execute([$hash]);
    if ($row = $revoked->fetch()) {
        reply(403, ['message' => 'This purchase code was revoked'.($row['reason'] ? ": {$row['reason']}" : '').'. Contact support@sarimtools.com.']);
    }
    // One production domain per code; development hosts don't count.
    $existing = $db->prepare("SELECT domain FROM activations WHERE code_hash = ? AND is_dev = 0 AND status = 'active'");
    $existing->execute([$hash]);
    $current = $existing->fetchColumn();
    if (! $dev && $current && $current !== $domain) {
        reply(409, ['message' => "This purchase code is already active on {$current}. Deactivate it there (Settings → License) or email support@sarimtools.com to move it."]);
    }

    $id = upsert($db, $hash, substr($code, 0, 4).'…'.substr($code, -4), $domain, $dev, $sale);
    reply(200, sign($config, ['aid' => $id, 'domain' => $domain, 'type' => $sale['license'] ?? 'Regular License', 'buyer' => $sale['buyer'] ?? null,
        'supported_until' => $sale['supported_until'] ?? null, 'item_id' => $sale['item']['id'] ?? null, 'demo' => false]));
}

function verify(PDO $db, array $config, array $in): never
{
    $payload = verified_payload($config, $in);
    if (! isset($payload['aid']) && ($payload['buyer'] ?? null) === 'author') {
        // Made with issue.php: only the private key can sign one, and there's no activation row to look up.
        reply(200, ['status' => 'active'] + sign($config, ['issued_at' => time()] + $payload));
    }
    $row = activation($db, (int) ($payload['aid'] ?? 0));
    if (! $row || $row['domain'] !== ($payload['domain'] ?? null)) {
        reply(200, ['status' => 'revoked', 'reason' => 'this activation no longer exists']);
    }
    if ($row['status'] !== 'active') {
        reply(200, ['status' => 'revoked', 'reason' => $row['status'] === 'released' ? 'it was deactivated on this domain' : ($row['reason'] ?: 'revoked by the author')]);
    }
    $db->prepare('UPDATE activations SET last_seen_at = ? WHERE id = ?')->execute([date('c'), $row['id']]);
    reply(200, ['status' => 'active'] + sign($config, ['issued_at' => time()] + $payload));
}

function deactivate(PDO $db, array $config, array $in): never
{
    $payload = verified_payload($config, $in);
    $db->prepare("UPDATE activations SET status = 'released', updated_at = ? WHERE id = ? AND status = 'active'")->execute([date('c'), (int) ($payload['aid'] ?? 0)]);
    reply(200, ['status' => 'released']);
}

/** Envato says whether the code is a real sale of your item. */
function envato_sale(array $config, string $code): array
{
    $ch = curl_init(rtrim($config['envato_api'] ?? 'https://api.envato.com', '/').'/v3/market/author/sale?code='.rawurlencode($code));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'FlowForge license server',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$config['envato_token']]]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status === 404) {
        reply(422, ['message' => 'Envato doesn\'t recognise this purchase code. Copy it again from your CodeCanyon downloads.']);
    }
    $sale = json_decode((string) $body, true);
    if ($status !== 200 || ! is_array($sale)) {
        reply(502, ['message' => 'Envato couldn\'t be reached to check the code. Try again in a few minutes.']);
    }
    if ((int) $config['item_id'] > 0 && (int) ($sale['item']['id'] ?? 0) !== (int) $config['item_id']) {
        reply(422, ['message' => 'This purchase code is for a different item.']);
    }

    return $sale;
}

function sign(array $config, array $payload): array
{
    $json = json_encode($payload + ['product' => 'flowforge', 'issued_at' => time()], JSON_UNESCAPED_SLASHES);
    $key = openssl_pkey_get_private((string) file_get_contents($config['private_key']));
    openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256);

    return ['token' => base64_encode($json), 'signature' => base64_encode($signature)];
}

function verified_payload(array $config, array $in): array
{
    $json = base64_decode((string) ($in['token'] ?? ''), true);
    $signature = base64_decode((string) ($in['signature'] ?? ''), true);
    $public = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($config['private_key'])))['key'];
    if ($json === false || $signature === false || openssl_verify($json, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
        reply(422, ['message' => 'Invalid license token.']);
    }

    return (array) json_decode($json, true);
}

function upsert(PDO $db, string $hash, string $hint, string $domain, bool $dev, array $sale): int
{
    $find = $db->prepare('SELECT id FROM activations WHERE code_hash = ? AND domain = ?');
    $find->execute([$hash, $domain]);
    $values = [$hint, $dev ? 1 : 0, $sale['buyer'] ?? null, $sale['license'] ?? null, $sale['supported_until'] ?? null, (string) ($sale['item']['id'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null, date('c')];
    if ($id = $find->fetchColumn()) {
        $db->prepare("UPDATE activations SET code_hint=?, is_dev=?, buyer=?, license_type=?, supported_until=?, item_id=?, ip=?, updated_at=?, status='active', reason=NULL WHERE id=?")->execute([...$values, $id]);

        return (int) $id;
    }
    $db->prepare("INSERT INTO activations (code_hint, is_dev, buyer, license_type, supported_until, item_id, ip, updated_at, code_hash, domain, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,'active',?)")
        ->execute([...$values, $hash, $domain, date('c')]);

    return (int) $db->lastInsertId();
}

function activation(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT * FROM activations WHERE id = ?');
    $q->execute([$id]);

    return $q->fetch() ?: null;
}

function normalize_host(string $host): string
{
    return (string) preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', strtolower(trim($host))));
}

function throttle(PDO $db, string $ip, string $path): void
{
    $window = date('YmdH');
    $db->prepare('DELETE FROM hits WHERE bucket < ?')->execute([$window]);
    $db->prepare('INSERT INTO hits (ip, bucket) VALUES (?, ?)')->execute([$ip, $window]);
    $count = $db->prepare('SELECT COUNT(*) FROM hits WHERE ip = ? AND bucket = ?');
    $count->execute([$ip, $window]);
    if ((int) $count->fetchColumn() > ($path === '/api/v1/activate' ? 30 : 300)) {
        reply(429, ['message' => 'Too many requests. Try again later.']);
    }
}

function migrate(PDO $db): void
{
    $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS activations (id {$id}, code_hash VARCHAR(64) NOT NULL, code_hint VARCHAR(20), domain VARCHAR(255) NOT NULL,
        is_dev TINYINT NOT NULL DEFAULT 0, buyer VARCHAR(100), license_type VARCHAR(40), supported_until VARCHAR(40), item_id VARCHAR(20),
        status VARCHAR(12) NOT NULL DEFAULT 'active', reason VARCHAR(255), ip VARCHAR(64), created_at VARCHAR(40), updated_at VARCHAR(40), last_seen_at VARCHAR(40))");
    $db->exec('CREATE TABLE IF NOT EXISTS hits (ip VARCHAR(64), bucket VARCHAR(12))');
}

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Minimal admin: find activations, release a domain (customer moved), revoke (refund, abuse) or restore. */
function admin(PDO $db, array $config): void
{
    session_start();
    header('Content-Type: text/html; charset=utf-8');
    if (isset($_POST['password'])) {
        $_SESSION['admin'] = $config['admin_password_hash'] !== '' && password_verify((string) $_POST['password'], $config['admin_password_hash']);
    }
    if (empty($_SESSION['admin'])) {
        echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Licenses</title><body style="font:15px system-ui;padding:40px"><form method="post"><h2>FlowForge licenses</h2><input type="password" name="password" placeholder="Admin password" autofocus> <button>Sign in</button></form>';

        return;
    }
    if (isset($_POST['action'], $_POST['id']) && hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $status = ['release' => 'released', 'revoke' => 'revoked', 'restore' => 'active'][$_POST['action']] ?? null;
        if ($status) {
            $db->prepare('UPDATE activations SET status = ?, reason = ?, updated_at = ? WHERE id = ?')->execute([$status, $_POST['reason'] ?? null, date('c'), (int) $_POST['id']]);
        }
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = $db->prepare('SELECT * FROM activations WHERE ? = \'\' OR domain LIKE ? OR buyer LIKE ? OR code_hash = ? ORDER BY id DESC LIMIT 200');
    $rows->execute([$q, "%{$q}%", "%{$q}%", hash('sha256', strtolower($q))]);
    $e = fn ($v) => htmlspecialchars((string) $v);
    echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Licenses</title><body style="font:14px system-ui;padding:24px">'
        .'<h2>FlowForge licenses</h2><form><input name="q" value="'.$e($q).'" placeholder="Domain, buyer or full purchase code" size="40"> <button>Search</button></form><table cellpadding="6" style="margin-top:16px;border-collapse:collapse">'
        .'<tr style="text-align:left"><th>Domain</th><th>Buyer</th><th>Code</th><th>License</th><th>Status</th><th>Last seen</th><th></th></tr>';
    foreach ($rows as $r) {
        $actions = '';
        foreach ($r['status'] === 'active' ? ['release' => 'Release domain', 'revoke' => 'Revoke'] : ['restore' => 'Restore'] as $action => $label) {
            $actions .= '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="'.$_SESSION['csrf'].'"><input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="action" value="'.$action.'">'
                .($action === 'revoke' ? '<input name="reason" placeholder="Reason" size="12">' : '').' <button>'.$label.'</button></form> ';
        }
        echo '<tr style="border-top:1px solid #ddd"><td>'.$e($r['domain']).($r['is_dev'] ? ' (dev)' : '').'</td><td>'.$e($r['buyer']).'</td><td>'.$e($r['code_hint']).'</td><td>'.$e($r['license_type']).'</td><td>'.$e($r['status']).($r['reason'] ? ': '.$e($r['reason']) : '').'</td><td>'.$e($r['last_seen_at']).'</td><td>'.$actions.'</td></tr>';
    }
    echo '</table>';
}
