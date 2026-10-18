<?php

namespace App\Domain\Demo;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live demo guard for the visitors' account. Deny by default: any request that changes something is
 * refused unless it is on this list (building and editing workflows, templates, the AI builder, theme).
 * New routes are therefore blocked in the demo automatically.
 */
class DemoRestrictions
{
    private const ALLOWED = [
        ['POST', 'logout'], ['PUT', 'settings/appearance'],
        ['POST', 'workflows'], ['PUT', 'workflows/*'], ['POST', 'workflows/*/duplicate'], ['POST', 'templates/*/use'],
        ['POST', 'assistant/conversations'], ['POST', 'assistant/conversations/*/messages'], ['DELETE', 'assistant/conversations/*'], ['PATCH', 'assistant/conversations/*'],
    ];

    /** Reads that start something outside the app. */
    private const BLOCKED_READS = ['credentials/*/connect', 'oauth/callback'];

    private const AI_MESSAGES_PER_HOUR = 40; // all visitors share one demo account and the author's AI key

    public function __construct(private DemoMode $demo) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->demo->isDemoUser($request->user())) {
            return $next($request);
        }

        $reads = $request->isMethod('GET') || $request->isMethod('HEAD');
        $allowed = $reads ? ! $request->is(...self::BLOCKED_READS)
            : collect(self::ALLOWED)->contains(fn ($rule) => $request->isMethod($rule[0]) && $request->is($rule[1]));
        if ($allowed && $request->isMethod('PUT') && $request->is('workflows/*') && $request->has('is_active')) {
            $allowed = false; // switching workflows on would run them for real
        }
        if ($allowed && $request->is('assistant/conversations/*/messages')) {
            if (RateLimiter::tooManyAttempts('demo-ai', self::AI_MESSAGES_PER_HOUR)) {
                return $this->refuse($request, 'The demo\'s AI builder is busy. Try again later, or install FlowForge with your own AI key.');
            }
            RateLimiter::hit('demo-ai', 3600);
        }

        return $allowed ? $next($request)
            : $this->refuse($request, 'Not available in the live demo: deleting, credentials, running or switching on workflows and settings are turned off. Buy FlowForge to use everything.');
    }

    private function refuse(Request $request, string $message): Response
    {
        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => $message], 403)
            : back()->with('error', $message);
    }
}
