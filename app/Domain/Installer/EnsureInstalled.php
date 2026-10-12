<?php

namespace App\Domain\Installer;

use App\Domain\System\SchemaUpgrader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes everything to /install until the install flag exists, and locks /install afterwards.
 */
class EnsureInstalled
{
    public static function isInstalled(): bool
    {
        return config('flowforge.installed') || is_file(self::flagPath());
    }

    public static function flagPath(): string
    {
        return config('flowforge.installed_flag') ?: storage_path('.installed');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $installing = $request->is('install', 'install/*');

        if (self::isInstalled()) {
            abort_if($installing, 404);
            if (! app()->runningUnitTests()) {
                SchemaUpgrader::ensureCurrent(); // finish any update whose migrations haven't run yet
            }

            return $next($request);
        }

        if ($installing) {
            return $next($request);
        }

        abort_if($request->is('api/*'), 503, 'FlowForge is not installed yet.');

        return redirect('/install');
    }
}
