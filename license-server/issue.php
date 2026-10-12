<?php

/**
 * Author-only: issue a license file directly with the private key (no Envato, no server), e.g. for your own
 * development copy or a demo you set up offline. Needs keys/private.pem, which never leaves your machines.
 *
 *   php license-server/issue.php 127.0.0.1 > storage/app/license.json
 *   php license-server/issue.php demo.example.com --demo > storage/app/license.json
 */
if (PHP_SAPI !== 'cli' || ! isset($argv[1])) {
    fwrite(STDERR, "usage: php issue.php <domain> [--demo]\n");
    exit(1);
}
$domain = (string) preg_replace('/^www\./', '', strtolower($argv[1]));
$demo = in_array('--demo', $argv, true);
$json = json_encode(['product' => 'flowforge', 'domain' => $domain, 'type' => $demo ? 'Demo' : 'Author', 'buyer' => 'author', 'demo' => $demo, 'issued_at' => time()], JSON_UNESCAPED_SLASHES);
openssl_sign($json, $signature, openssl_pkey_get_private((string) file_get_contents(__DIR__.'/keys/private.pem')), OPENSSL_ALGO_SHA256);
echo json_encode(['token' => base64_encode($json), 'signature' => base64_encode($signature), 'status' => 'active', 'activated_at' => time(), 'checked_at' => time(), 'code_hint' => 'author'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
