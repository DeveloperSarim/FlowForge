<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WebhookGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound webhooks: /api/webhook/{token}. Active workflows get a queued run; inactive ones just
 * capture the request as sample data for the editor's variable picker and test runs. Bodies over
 * flowforge.webhook_max_kb get a 413, credential-looking headers and query keys are stored as "[redacted]",
 * and a trigger with a sender check (WebhookGuard::verify) refuses unverified requests with a 401.
 */
class WebhookController extends Controller
{
    /** @param  string|null  $trigger  which webhook trigger of the workflow (/api/webhook/{token}/{trigger}); none: the main one */
    public function __invoke(Request $request, string $token, ?string $trigger = null): Response
    {
        if ($tooLarge = WebhookGuard::tooLarge($request)) {
            return $tooLarge;
        }

        $workflow = Workflow::query()->where('webhook_token', $token)->first();

        if (! $workflow || ($workflow->triggerSpec($trigger)['type'] ?? null) !== 'webhook') {
            return response()->json(['message' => 'No webhook workflow at this URL.'], 404);
        }

        if ($handshake = $this->handshake($request)) {
            return $handshake;
        }

        $method = strtoupper($workflow->triggerConfig($trigger)['method'] ?? 'ANY');
        if ($method !== 'ANY' && $request->method() !== $method) {
            return response()->json(['message' => "This webhook only accepts {$method}."], 405);
        }

        // Checked for inactive workflows too, so a stranger can't replace the editor's sample data.
        $spec = $workflow->triggerSpec($trigger);
        if ($refused = WebhookGuard::verify($request, $spec, $workflow->triggerConfig($trigger))) {
            return response()->json(['message' => $refused], 401);
        }

        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if ($body === [] && ($raw = $request->getContent()) !== '') {
            $body = ['raw' => mb_substr($raw, 0, 1_000_000)];
        }

        $dataPath = $spec['data_path'] ?? null;
        $payload = WebhookGuard::redact([
            'data' => $dataPath ? data_get($body, $dataPath, $body) : $body,
            'query' => $request->query(),
            'headers' => array_map(fn ($values) => implode(', ', $values), $request->headers->all()),
            'method' => $request->method(),
        ]);

        if (! $workflow->is_active) {
            $workflow->mergeTriggerState(['sample' => $payload, 'sample_at' => now()->toIso8601String()], $trigger);

            return response()->json(['status' => 'captured', 'message' => 'Workflow is inactive; request saved as test data.']);
        }

        // ponytail: runs are queued, so they start on the next cron tick (<= 1 min); add an afterResponse/sync mode if instant webhooks matter.
        $execution = $workflow->start('webhook', $payload, trigger: $trigger);

        // 200, not 202: several providers (LINE, some "verify URL" buttons) only accept exactly 200.
        return response()->json(['status' => 'queued', 'execution_id' => $execution->id]);
    }

    /**
     * Answer the URL-verification handshakes providers send before they deliver events.
     */
    private function handshake(Request $request): ?Response
    {
        // Trello (and some others) verify the URL with a HEAD request.
        if ($request->isMethod('HEAD')) {
            return response('', 200);
        }
        // Asana: echo X-Hook-Secret back in the response header.
        if ($secret = $request->header('X-Hook-Secret')) {
            return response('', 200, ['X-Hook-Secret' => $secret]);
        }
        // Meta (WhatsApp, Messenger, Instagram): GET ?hub.mode=subscribe&hub.challenge=… (PHP turns dots into underscores)
        if ($request->isMethod('GET') && $request->query('hub_mode') === 'subscribe' && $request->filled('hub_challenge')) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }
        // WooCommerce pings a new webhook with an unsigned "webhook_id=…" form body.
        if ($request->isMethod('POST') && preg_match('/^webhook_id=\d+$/', $request->getContent())) {
            return response('', 200);
        }
        // Microsoft Graph subscriptions: ?validationToken=…
        if ($request->filled('validationToken')) {
            return response((string) $request->query('validationToken'), 200, ['Content-Type' => 'text/plain']);
        }
        // Slack Events API ({"type":"url_verification","challenge":…}) and monday.com ({"challenge":…})
        $json = $request->isJson() ? $request->json()->all() : [];
        if (isset($json['challenge']) && (($json['type'] ?? null) === 'url_verification' || count($json) === 1)) {
            return response()->json(['challenge' => $json['challenge']]);
        }

        return null;
    }
}
