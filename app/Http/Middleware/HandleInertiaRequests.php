<?php

namespace App\Http\Middleware;

use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [...$request->user()->only('id', 'name', 'email', 'theme', 'role'), 'can' => $request->user()->abilities()] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'appCount' => fn () => $request->user() ? app(ManifestLoader::class)->count() : 0,
            // Last time `schedule:run` ticked; the layout warns when cron looks dead.
            'timezone' => fn () => $request->user() ? app(SystemSettings::class)->timezone() : null,
            'scheduler' => fn () => $request->user() ? rescue(fn () => Cache::get('flowforge:heartbeat'), null, false) : null,
        ];
    }
}
