<?php

namespace App\Domain\License;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class LicenseController extends Controller
{
    public function __construct(private License $license) {}

    /** Shown whenever an installed copy has no valid license for this domain. */
    public function show(): Response|RedirectResponse
    {
        if ($this->license->valid()) {
            return redirect('/');
        }

        return Inertia::render('License/Activate', ['problem' => $this->license->problem(), 'domain' => License::host()]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $this->activateFrom($request);

        return redirect('/')->with('success', 'License activated. Thanks for buying FlowForge!');
    }

    /** Settings → License: free this domain (e.g. before moving to another one). */
    public function deactivate(): RedirectResponse
    {
        $this->license->deactivate();

        return redirect('/license');
    }

    public function refresh(): JsonResponse
    {
        $this->license->refresh();

        return response()->json(['license' => $this->license->summary()]);
    }

    /** Settings → License, demo copies: the visitors' login shown on the sign-in page. */
    public function saveDemo(Request $request, DemoMode $demo): RedirectResponse
    {
        abort_unless($demo->active(), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:6', 'max:100']]);
        $demo->saveCredentials($data['email'], $data['password']);

        return back()->with('success', 'Demo login saved.');
    }

    public function snapshotDemo(DemoMode $demo): RedirectResponse
    {
        abort_unless($demo->active(), 404);

        return back()->with('success', 'Saved '.$demo->snapshot().' workflows as the demo starting point. Every hour the demo returns to them.');
    }

    /** Shared with the installer's license step. */
    public function activateFrom(Request $request): array
    {
        $code = $request->validate(['purchase_code' => ['required', 'string', 'max:100']])['purchase_code'];
        try {
            $payload = $this->license->activate($code);
            if (! empty($payload['demo']) && rescue(fn () => Schema::hasTable('users'), false, false)) {
                app(DemoMode::class)->ensureUser();
            }

            return $payload;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['purchase_code' => $e->getMessage()]);
        }
    }
}
