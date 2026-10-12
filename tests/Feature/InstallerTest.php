<?php

namespace Tests\Feature;

use App\Domain\Installer\EnvManager;
use App\Domain\System\SystemMail;
use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pretend this is a fresh upload: no override, flag file somewhere that doesn't exist yet.
        config(['flowforge.installed' => false, 'flowforge.installed_flag' => sys_get_temp_dir().'/ff-installed-'.uniqid()]);
    }

    public function test_everything_redirects_to_the_installer_until_finished(): void
    {
        $this->get('/workflows')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertInertia(fn ($page) => $page->component('Install/Wizard'));
    }

    public function test_email_step_tests_saves_and_can_be_skipped(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'owner@example.com']);
        $smtp = ['host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'ff@example.com', 'password' => 'pw', 'from_address' => 'ff@example.com', 'notify_email' => 'owner@example.com'];

        $this->postJson('/install/mail/test', $smtp)->assertOk()->assertJsonPath('ok', true);
        Mail::assertSent(SystemMail::class, fn ($m) => $m->hasTo('owner@example.com'));

        $this->post('/install/mail', $smtp)->assertRedirect('/install');
        $settings = app(SystemSettings::class);
        $this->assertSame('mail.example.com', $settings->mail()['host']);
        $this->assertSame(['owner@example.com'], $settings->notifications()['recipients']);

        $this->post('/install/mail/skip')->assertRedirect('/install');
        $this->assertTrue(app(SystemSettings::class)->get('mail_skipped'));
    }

    public function test_finish_waits_for_a_real_cron_run_and_keeps_encrypted_settings(): void
    {
        $this->app->instance(EnvManager::class, new EnvManager(sys_get_temp_dir().'/ff-env-'.uniqid()));
        $this->post('/install/admin', ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1', 'timezone' => 'Asia/Karachi'])
            ->assertRedirect('/install');
        $this->assertSame('Asia/Karachi', app(SystemSettings::class)->timezone());
        app(SystemSettings::class)->set('mail', ['host' => 'mail.example.com', 'port' => 587, 'password' => 'smtp-secret', 'from_address' => 'ff@example.com']);

        $this->getJson('/install/heartbeat')->assertJsonPath('verified', false);
        $this->post('/install/finish')->assertStatus(409);

        Cache::store('database')->forever('flowforge:heartbeat', now()->subSeconds(30)->toIso8601String());
        $this->getJson('/install/heartbeat')->assertJsonPath('verified', true);
        $this->post('/install/finish')->assertRedirect(url('/login'));

        $this->assertFileExists(config('flowforge.installed_flag'));
        $this->assertSame('smtp-secret', (new SystemSettings)->mail()['password']); // still decryptable: no key rotation
    }

    public function test_mail_password_is_never_sent_back_to_the_browser(): void
    {
        $this->config(['flowforge.installed' => true]);
        app(SystemSettings::class)->set('mail', ['host' => 'h', 'port' => 587, 'encryption' => 'tls', 'password' => 'super-secret', 'from_address' => 'a@b.c']);
        app(SystemSettings::class)->set('ai', ['provider' => 'openai', 'api_key' => 'sk-secret', 'model' => 'gpt-5-mini', 'base_url' => 'https://api.openai.com/v1']);

        $html = $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('super-secret', $html);
        $this->assertStringNotContainsString('sk-secret', $html);
    }

    private function config(array $values): void
    {
        config($values);
    }
}
