<?php

namespace App\Http\Controllers;

use App\Domain\System\Notifier;
use App\Domain\System\SystemMail;
use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Forgot password": a 6-digit code by email (hashed in password_reset_tokens, 15 minutes, 5 tries),
 * then a new password. Answers the same whether or not the account exists.
 */
class PasswordResetController extends Controller
{
    private const TTL_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function show(SystemSettings $settings): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['mailConfigured' => $settings->mail() !== null]);
    }

    public function send(Request $request, Notifier $notifier): RedirectResponse
    {
        $email = Str::lower($request->validate(['email' => ['required', 'email', 'max:255']])['email']);
        if (! $notifier->configure()) {
            throw ValidationException::withMessages(['email' => 'Email is not set up on this server, so a code cannot be sent.']);
        }
        if (RateLimiter::tooManyAttempts($sendKey = "password-reset-send:$email", 3)) {
            throw ValidationException::withMessages(['email' => 'Too many codes requested. Try again in '.ceil(RateLimiter::availableIn($sendKey) / 60).' minutes.']);
        }
        RateLimiter::hit($sendKey, 600);

        if ($user = User::query()->where('email', $email)->first()) {
            $code = (string) random_int(100000, 999999);
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make($code), 'created_at' => now()]);
            RateLimiter::clear("password-reset:$email");

            try {
                Mail::mailer(Notifier::MAILER)->to($user->email)->send(new SystemMail(
                    "Your FlowForge reset code: $code",
                    "Enter this code to set a new password: **$code**\n\nIt expires in ".self::TTL_MINUTES." minutes. If you didn't ask for it, ignore this email: your password stays the same.",
                ));
            } catch (Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['email' => 'The email could not be sent. Check the email settings, or ask your administrator.']);
            }
        }

        return back()->with('success', 'If that email has an account, a 6-digit code is on its way.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $email = Str::lower($data['email']);
        $key = "password-reset:$email";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Request a new one.']);
        }

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        $user = User::query()->where('email', $email)->first();
        if (! $row || ! $user || Carbon::parse($row->created_at)->addMinutes(self::TTL_MINUTES)->isPast() || ! Hash::check($data['code'], $row->token)) {
            RateLimiter::hit($key, self::TTL_MINUTES * 60);
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        RateLimiter::clear($key);

        return redirect('/login')->with('success', 'Password changed. Sign in with your new password.');
    }
}
