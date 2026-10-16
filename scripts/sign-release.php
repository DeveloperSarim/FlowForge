<?php

/**
 * Signs a release's SHA256SUMS.txt with the Ed25519 release key: writes <file>.sig (base64 detached signature).
 * One-click updates refuse a release whose SHA256SUMS.txt isn't signed by the key in config/flowforge.php.
 * Key: $FLOWFORGE_SIGNING_KEY, default ~/.flowforge/release-signing.key (made by scripts/make-signing-key.php).
 *
 * Usage: php scripts/sign-release.php dist/SHA256SUMS.txt
 */
if (! function_exists('sodium_crypto_sign_detached')) {
    require __DIR__.'/../vendor/autoload.php'; // paragonie/sodium_compat when ext-sodium is missing
}

$fail = function (string $message) {
    fwrite(STDERR, "✗ {$message}\n");
    exit(1);
};

$file = $argv[1] ?? $fail('Usage: php scripts/sign-release.php dist/SHA256SUMS.txt');
$keyFile = getenv('FLOWFORGE_SIGNING_KEY') ?: getenv('HOME').'/.flowforge/release-signing.key';
is_file($keyFile) || $fail("No release signing key at {$keyFile}. Without a signature, installs refuse the update. Restore the key (or set FLOWFORGE_SIGNING_KEY); only for a brand-new key run scripts/make-signing-key.php.");
is_file($file) || $fail("{$file} not found.");

$secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $fail("{$keyFile} is not a release signing key.");
}
$public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
// Signing with a key the installs don't know would make every one-click update fail.
str_contains((string) file_get_contents(__DIR__.'/../config/flowforge.php'), "'{$public}'")
    || $fail("This key's public key ({$public}) is not the one in config/flowforge.php (updates.public_key).");

file_put_contents("{$file}.sig", base64_encode(sodium_crypto_sign_detached((string) file_get_contents($file), $secret))."\n");
echo "  signed {$file}.sig\n";
