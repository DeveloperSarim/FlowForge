<?php

namespace Tests\Feature;

use App\Domain\License\License;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unlicensed_copy_shows_activation_and_runs_nothing(): void
    {
        $workflow = Workflow::query()->create(['name' => 'W', 'graph' => Workflow::defaultGraph()]);
        @unlink($this->licensePath);
        $this->app->forgetInstance(License::class);

        $this->actingAs(User::factory()->create())->get('/workflows')->assertRedirect('/license');
        $this->get('/license')->assertOk()->assertInertia(fn ($page) => $page->component('License/Activate'));
        $this->postJson('/api/mcp', [])->assertStatus(503);
        $this->expectException(RuntimeException::class);
        $workflow->start('manual', []);
    }

    public function test_tampered_or_foreign_tokens_are_rejected(): void
    {
        $this->writeLicense(['domain' => 'someone-else.com']);
        $this->assertStringContainsString('activated for someone-else.com', app(License::class)->problem());

        $state = json_decode(file_get_contents($this->licensePath), true);
        $payload = json_decode(base64_decode($state['token']), true);
        $state['token'] = base64_encode(json_encode(['domain' => License::host()] + $payload)); // edited, signature no longer matches
        file_put_contents($this->licensePath, json_encode($state));
        $this->app->forgetInstance(License::class);
        $this->assertStringContainsString('invalid', app(License::class)->problem());
    }

    public function test_activation_stores_a_signed_domain_bound_token_and_server_errors_are_shown(): void
    {
        @unlink($this->licensePath);
        $this->app->forgetInstance(License::class);
        $code = '8f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
        Http::fake(['license.sarimtools.com/api/v1/activate' => Http::sequence()
            ->push(['message' => 'This purchase code is already active on other.com. Deactivate it there first.'], 409)
            ->push($this->signLicense(['domain' => License::host(), 'type' => 'Regular License', 'buyer' => 'zara']))]);

        $this->post('/license', ['purchase_code' => $code])->assertSessionHasErrors(['purchase_code' => 'This purchase code is already active on other.com. Deactivate it there first.']);
        $this->post('/license', ['purchase_code' => $code])->assertRedirect('/');

        Http::assertSent(fn ($r) => $r['purchase_code'] === $code && $r['domain'] === License::host() && $r['product'] === 'flowforge');
        $license = app(License::class);
        $this->assertTrue($license->valid());
        $this->assertSame('zara', $license->summary()['buyer']);
        $this->assertStringNotContainsString($code, file_get_contents($this->licensePath), 'the purchase code is stored encrypted');
    }

    public function test_the_daily_check_applies_revocations(): void
    {
        Http::fake(['license.sarimtools.com/api/v1/verify' => Http::response(['status' => 'revoked', 'reason' => 'refunded'])]);
        app(License::class)->refresh();

        $this->assertStringContainsString('revoked: refunded', app(License::class)->problem());
    }

    public function test_the_installer_needs_the_license_before_the_database_step(): void
    {
        config(['flowforge.installed' => false, 'flowforge.installed_flag' => sys_get_temp_dir().'/ff-installed-'.uniqid()]);
        @unlink($this->licensePath);
        $this->app->forgetInstance(License::class);

        $this->get('/install')->assertInertia(fn ($page) => $page->where('state.license', false));
        $this->post('/install/admin', ['name' => 'x'])->assertForbidden();
    }
}
