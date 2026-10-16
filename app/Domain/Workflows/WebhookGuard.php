<?php

namespace App\Domain\Workflows;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared checks for public inbound endpoints (/api/webhook, /api/voice): the body size limit, blanking
 * credentials out of headers and query strings before they are stored, and the optional sender check.
 *
 * Sender check spec = {type: header|basic|hmac|stripe|slack, header?, encoding? (hex|base64), prefix?, username?};
 * the secret is the trigger's "signing_secret" input. App triggers declare it as "signature" in their manifest;
 * the core webhook trigger takes it from its settings ("Check incoming requests").
 */
class WebhookGuard
{
    public const REDACTED = '[redacted]';

    public const TYPES = ['header', 'basic', 'hmac', 'stripe', 'slack'];

    /** Stripe and Slack signatures older than this (seconds) are refused as replays. */
    public const TOLERANCE = 300;

    private const SENSITIVE_HEADER = '/auth|token|secret|passw(or)?d|api[-_]?key|signature|hmac|session|credential|cookie/i';

    private const SENSITIVE_QUERY = '/token|secret|passw(or)?d|signature|hmac|api[-_]?key|(^|[-_.])(key|sig|auth|authorization)($|[-_.])/i';

    /** A 413 answer when the body is over flowforge.webhook_max_kb, else null. */
    public static function tooLarge(Request $request): ?JsonResponse
    {
        $kb = (int) config('flowforge.webhook_max_kb', 5120);
        if ((int) $request->header('Content-Length') <= $kb * 1024 && strlen($request->getContent()) <= $kb * 1024) {
            return null;
        }

        return response()->json(['message' => 'The request is too large. This URL accepts up to '.round($kb / 1024, 1).' MB.'], 413);
    }

    /** A stored request ({data, query, headers, method}) with credential-looking headers and query keys blanked out. */
    public static function redact(array $payload): array
    {
        foreach (['headers' => self::SENSITIVE_HEADER, 'query' => self::SENSITIVE_QUERY] as $part => $pattern) {
            foreach (is_array($payload[$part] ?? null) ? $payload[$part] : [] as $name => $value) {
                $payload[$part][$name] = preg_match($pattern, (string) $name) ? self::REDACTED : $value;
            }
        }

        return $payload;
    }

    /**
     * Checks who sent the request. Returns why it was refused, or null when it passes or no check is set.
     *
     * @param  array|null  $trigger  the manifest trigger (its "signature" spec, if any)
     * @param  array  $config  the trigger's settings
     */
    public static function verify(Request $request, ?array $trigger, array $config): ?string
    {
        $secret = (string) ($config['signing_secret'] ?? '');
        if ($spec = $trigger['signature'] ?? null) {
            if ($secret === '') {
                return null; // app triggers are checked once the user pastes the signing secret
            }
        } else {
            $type = $config['verify'] ?? 'none';
            if (! in_array($type, self::TYPES, true)) {
                return null;
            }
            if ($secret === '') {
                return 'This webhook checks incoming requests, but its trigger has no secret yet.';
            }
            $spec = ['type' => $type, 'header' => $config[$type === 'header' ? 'secret_header' : 'signature_header'] ?? null,
                'encoding' => $config['encoding'] ?? 'hex', 'prefix' => $config['prefix'] ?? '', 'username' => $config['username'] ?? ''];
        }

        $body = $request->getContent();
        $failed = 'The request could not be verified: the secret or signature is missing or wrong.';

        switch ($spec['type']) {
            case 'header':
                return hash_equals($secret, (string) $request->header($spec['header'] ?: 'X-Webhook-Secret')) ? null : $failed;
            case 'basic':
                $user = hash_equals((string) ($spec['username'] ?? ''), (string) $request->getUser());
                $password = hash_equals($secret, (string) $request->getPassword());

                return $user && $password ? null : $failed;
            case 'hmac':
                $given = trim((string) $request->header($spec['header'] ?: 'X-Hub-Signature-256'));
                $prefix = (string) ($spec['prefix'] ?? '');
                if ($prefix !== '' && stripos($given, $prefix) === 0) {
                    $given = substr($given, strlen($prefix)); // "sha256=" on GitHub; optional so one setting fits all apps
                }
                $base64 = ($spec['encoding'] ?? 'hex') === 'base64';
                $expected = $base64 ? base64_encode(hash_hmac('sha256', $body, $secret, true)) : hash_hmac('sha256', $body, $secret);

                return $given !== '' && hash_equals($expected, $base64 ? $given : strtolower($given)) ? null : $failed;
            case 'stripe':
                // Stripe-Signature: t=1492774577,v1=<hex hmac of "t.body">[,v1=…]
                $parts = [];
                foreach (explode(',', (string) $request->header('Stripe-Signature')) as $item) {
                    [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
                    $parts[$key][] = $value;
                }
                $timestamp = $parts['t'][0] ?? '';
                $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
                $match = collect($parts['v1'] ?? [])->contains(fn ($sig) => hash_equals($expected, $sig));

                return self::fresh($timestamp, $match) ?? ($match ? null : $failed);
            case 'slack':
                // X-Slack-Signature: v0=<hex hmac of "v0:timestamp:body">, X-Slack-Request-Timestamp: timestamp
                $timestamp = (string) $request->header('X-Slack-Request-Timestamp');
                $match = hash_equals('v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret), (string) $request->header('X-Slack-Signature'));

                return self::fresh($timestamp, $match) ?? ($match ? null : $failed);
        }

        return $failed;
    }

    /** Replay protection: a correctly signed request is still refused when its timestamp is over 5 minutes off. */
    private static function fresh(string $timestamp, bool $signed): ?string
    {
        return $signed && (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE)
            ? 'The request could not be verified: its timestamp is more than 5 minutes old.'
            : null;
    }
}
