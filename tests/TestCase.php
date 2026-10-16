<?php

namespace Tests;

use App\Domain\Integrations\UrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No DNS in tests: every host name (api.test, hooks.test…) is a public address.
        UrlGuard::resolveUsing(fn () => ['93.184.215.14']);
    }

    protected function tearDown(): void
    {
        UrlGuard::resolveUsing(null);
        parent::tearDown();
    }
}
