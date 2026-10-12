<?php

namespace App\Domain\Demo;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Settings → Live demo (admins, only with FLOWFORGE_DEMO=true): the visitors' login and the reset point. */
class DemoController extends Controller
{
    public function __construct(private DemoMode $demo) {}

    public function save(Request $request): RedirectResponse
    {
        abort_unless($this->demo->active(), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:6', 'max:100']]);
        $this->demo->saveCredentials($data['email'], $data['password']);

        return back()->with('success', 'Demo login saved.');
    }

    public function snapshot(): RedirectResponse
    {
        abort_unless($this->demo->active(), 404);

        return back()->with('success', 'Saved '.$this->demo->snapshot().' workflows as the demo starting point. Every hour the demo returns to them.');
    }
}
