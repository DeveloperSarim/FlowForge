<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** "Authorization: Bearer ff_…" → the token's user for this request (no session). */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findPlain((string) $request->bearerToken());
        if (! $token?->user || $token->user->isInvited()) {
            return response()->json(['error' => 'Missing or invalid token. Create one in FlowForge under Settings → API & MCP.'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="FlowForge"');
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        Auth::setUser($token->user);

        return $next($request);
    }
}
