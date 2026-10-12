<?php

namespace Tests;

use App\Domain\License\License;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var array{0: \OpenSSLAsymmetricKey, 1: string}|null a throwaway signing key, made once per run */
    private static ?array $licenseKeys = null;

    protected string $licensePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test runs as a licensed copy of the test host, signed by a test key the app is told to trust.
        if (! self::$licenseKeys) {
            $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::$licenseKeys = [$private, openssl_pkey_get_details($private)['key']];
        }
        $this->licensePath = sys_get_temp_dir().'/ff-license-'.uniqid().'.json';
        config(['flowforge.license.path' => $this->licensePath]);
        $this->app->singleton(License::class, fn () => new License(self::$licenseKeys[1]));
        $this->writeLicense(['domain' => License::host()]);
    }

    protected function tearDown(): void
    {
        @unlink($this->licensePath);
        parent::tearDown();
    }

    /** Writes a license file holding a token signed with the test key. */
    protected function writeLicense(array $payload, array $state = []): void
    {
        $json = json_encode($payload + ['product' => 'flowforge', 'type' => 'Regular License', 'issued_at' => time()]);
        openssl_sign($json, $signature, self::$licenseKeys[0], OPENSSL_ALGO_SHA256);
        file_put_contents($this->licensePath, json_encode($state + ['token' => base64_encode($json), 'signature' => base64_encode($signature), 'status' => 'active', 'checked_at' => time()]));
        $this->app->forgetInstance(License::class);
    }

    /** Signs a token like the license server does (for tests that fake the server). */
    protected function signLicense(array $payload): array
    {
        $json = json_encode($payload + ['product' => 'flowforge', 'issued_at' => time()]);
        openssl_sign($json, $signature, self::$licenseKeys[0], OPENSSL_ALGO_SHA256);

        return ['token' => base64_encode($json), 'signature' => base64_encode($signature)];
    }
}
