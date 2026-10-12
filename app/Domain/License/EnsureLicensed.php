<?php

namespace App\Domain\License;

use App\Domain\Installer\EnsureInstalled;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An installed copy only runs with a valid license for this domain; otherwise it shows the activation page. */
class EnsureLicensed
{
    /** Reachable without a license: activation itself, signing out, and public crawler files. */
    private const OPEN = ['license', 'license/*', 'logout', 'robots.txt', 'sitemap.xml', 'llms.txt', 'llms-full.txt'];

    public function __construct(private License $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! EnsureInstalled::isInstalled() || $request->is('install', 'install/*', ...self::OPEN) || $this->license->valid()) {
            return $next($request);
        }

        return $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => $this->license->problem()], 503)
            : redirect('/license');
    }
}
