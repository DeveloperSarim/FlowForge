<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\ManifestLoader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        $catalog = app(ManifestLoader::class)->catalogMap();

        return Inertia::render('Auth/Login', [
            'icons' => collect(['webhook', 'slack', 'openai', 'hubspot', 'google_sheets', 'gmail'])->mapWithKeys(fn ($key) => [$key => $catalog[$key]['icon'] ?? null]),
            'appCount' => count($catalog),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
