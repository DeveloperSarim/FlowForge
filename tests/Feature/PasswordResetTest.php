<?php

namespace Tests\Feature;

use App\Domain\System\SystemMail;
use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_code_resets_the_password_once(): void
    {
        Mail::fake();
        app(SystemSettings::class)->set('mail', ['host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'ff@example.com']);
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('success'); // same answer, no mail
        Mail::assertNothingSent();

        $this->post('/forgot-password', ['email' => 'Owner@Example.com'])->assertSessionHas('success');
        $code = null;
        Mail::assertSent(SystemMail::class, function (SystemMail $mail) use (&$code) {
            $code = substr($mail->heading, -6);

            return $mail->hasTo('owner@example.com');
        });

        $new = ['email' => 'owner@example.com', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'];
        $this->post('/reset-password', $new + ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->post('/reset-password', $new + ['code' => $code])->assertRedirect('/login');
        $this->assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));

        $this->post('/reset-password', $new + ['code' => $code])->assertSessionHasErrors('code'); // single use
    }

    public function test_without_email_settings_the_page_explains_the_alternative(): void
    {
        $this->get('/forgot-password')->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword')->where('mailConfigured', false));
        $this->post('/forgot-password', ['email' => 'owner@example.com'])->assertSessionHasErrors('email');
    }
}
