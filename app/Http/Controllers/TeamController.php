<?php

namespace App\Http\Controllers;

use App\Domain\System\Notifier;
use App\Domain\System\SystemMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings → Team (admins): invite people and choose what they can do. Invitees get a link (by email
 * when SMTP is set up, otherwise the admin copies it) and choose their own password.
 */
class TeamController extends Controller
{
    private const INVITE_DAYS = 7;

    public static function members(): array
    {
        return User::query()->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => array_values((array) $user->permissions),
                'invited' => $user->isInvited(),
                'invite_expired' => $user->isInvited() && $user->invite_expires_at?->isPast(),
            ])->all();
    }

    public function invite(Request $request, Notifier $notifier): JsonResponse
    {
        $data = $this->validated($request, [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $member = User::query()->create([
            'name' => ($data['name'] ?? null) ?: Str::of($data['email'])->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
            'email' => Str::lower($data['email']),
            'password' => Str::random(40), // never used: they choose their own when accepting
            'role' => $data['role'],
            'permissions' => $data['role'] === 'member' ? $data['permissions'] : null,
        ]);

        return response()->json($this->sendInvite($member, $notifier) + ['members' => self::members()]);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        abort_if($member->isOwner(), 403, 'The owner always has full access.');
        abort_if($member->is($request->user()), 403, 'You can\'t change your own access. Ask the owner or another admin.');
        $data = $this->validated($request);
        $member->update(['role' => $data['role'], 'permissions' => $data['role'] === 'member' ? $data['permissions'] : null]);

        return response()->json(['members' => self::members()]);
    }

    public function resendInvite(User $member, Notifier $notifier): JsonResponse
    {
        abort_unless($member->isInvited(), 422, 'This person has already joined.');

        return response()->json($this->sendInvite($member, $notifier) + ['members' => self::members()]);
    }

    public function destroy(Request $request, User $member): JsonResponse
    {
        abort_if($member->isOwner(), 403, 'The owner can\'t be removed.');
        abort_if($member->is($request->user()), 403, 'You can\'t remove yourself.');
        $member->delete();

        return response()->json(['members' => self::members()]);
    }

    public function showInvite(string $token): Response
    {
        $member = $this->invitee($token);

        return Inertia::render('Auth/AcceptInvite', [
            'token' => $token,
            'member' => $member ? ['name' => $member->name, 'email' => $member->email] : null,
        ]);
    }

    public function acceptInvite(Request $request, string $token): RedirectResponse
    {
        $member = $this->invitee($token) ?? abort(404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $member->forceFill(['name' => $data['name'], 'password' => $data['password'], 'invite_token' => null, 'invite_expires_at' => null, 'email_verified_at' => now()])->save();
        Auth::login($member, remember: true);
        $request->session()->regenerate();

        return redirect('/')->with('success', 'Welcome to FlowForge!');
    }

    private function invitee(string $token): ?User
    {
        return User::query()->where('invite_token', hash('sha256', $token))->where('invite_expires_at', '>', now())->first();
    }

    /** A fresh single-use link (only its hash is stored), emailed when SMTP is configured. */
    private function sendInvite(User $member, Notifier $notifier): array
    {
        $token = Str::random(48);
        $member->forceFill(['invite_token' => hash('sha256', $token), 'invite_expires_at' => now()->addDays(self::INVITE_DAYS)])->save();
        $url = url("/invite/{$token}");

        $emailed = false;
        if ($notifier->configure()) {
            try {
                Mail::mailer(Notifier::MAILER)->to($member->email)->send(new SystemMail(
                    'You\'re invited to FlowForge',
                    request()->user()->name.' invited you to their FlowForge workspace. Choose a password to join; the link works for '.self::INVITE_DAYS.' days.',
                    $url,
                    'Accept invite',
                ));
                $emailed = true;
            } catch (Throwable $e) {
                report($e); // the admin can still copy the link
            }
        }

        return ['invite_url' => $url, 'emailed' => $emailed];
    }

    private function validated(Request $request, array $extra = []): array
    {
        $data = $request->validate($extra + [
            'role' => ['required', Rule::in(['admin', 'member'])],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(User::PERMISSIONS))],
        ]);
        $data['permissions'] = array_values(array_unique($data['permissions'] ?? []));
        if ($data['role'] === 'member' && $data['permissions'] === []) {
            abort(422, 'Give the member access to at least one thing.');
        }

        return $data;
    }
}
