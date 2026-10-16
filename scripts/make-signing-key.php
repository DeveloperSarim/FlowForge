<?php

/**
 * Creates the Ed25519 key pair that signs release SHA256SUMS.txt files (scripts/sign-release.php).
 * The secret key goes to $FLOWFORGE_SIGNING_KEY (default ~/.flowforge/release-signing.key, mode 600) and is
 * never printed; put the printed public key in config/flowforge.php (updates.public_key).
 * Refuses to overwrite an existing key: installs only accept updates signed with the key they ship with.
 *
 * Usage: php scripts/make-signing-key.php
 */
if (! function_exists('sodium_crypto_sign_keypair')) {
    require __DIR__.'/../vendor/autoload.php'; // paragonie/sodium_compat when ext-sodium is missing
}

$path = getenv('FLOWFORGE_SIGNING_KEY') ?: getenv('HOME').'/.flowforge/release-signing.key';
if (file_exists($path)) {
    fwrite(STDERR, "A key already exists at {$path}; not overwriting it. Move it away first if you really mean to replace it.\n");
    exit(1);
}
if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
    fwrite(STDERR, 'Could not create '.dirname($path)."\n");
    exit(1);
}

$pair = sodium_crypto_sign_keypair();
umask(0077);
if (file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair))."\n", LOCK_EX) === false || ! chmod($path, 0600)) {
    fwrite(STDERR, "Could not write {$path}\n");
    exit(1);
}

echo "Secret key written to {$path} (mode 600). Back it up somewhere safe and never commit it.\n";
echo 'Public key for config/flowforge.php (updates.public_key): '.base64_encode(sodium_crypto_sign_publickey($pair))."\n";
