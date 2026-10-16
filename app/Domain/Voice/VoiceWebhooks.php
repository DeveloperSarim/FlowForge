<?php

namespace App\Domain\Voice;

use App\Domain\Voice\Jobs\SendVoiceWebhook;
use App\Domain\Voice\Models\VoiceAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhooks set on a voice agent: when a call ends or a caller books, the call or booking is POSTed as JSON
 * to each URL that listens for that event (no workflow needed). Bodies are signed with the agent's secret
 * in X-FlowForge-Signature (sha256 HMAC of the raw body). Deliveries are queued and retried by SendVoiceWebhook.
 */
class VoiceWebhooks
{
    public const EVENTS = ['call_ended' => 'When a call ends', 'booking_created' => 'When someone books'];

    private const TYPE_EVENTS = ['voice_call' => 'call_ended', 'voice_booking' => 'booking_created'];

    /** Queue a delivery to each of the agent's webhooks that listens for this event. */
    public function dispatch(string $type, int $agentId, array $data): void
    {
        $event = self::TYPE_EVENTS[$type] ?? null;
        $agent = $event ? VoiceAgent::query()->find($agentId) : null;
        foreach ((array) $agent?->setting('webhooks', []) as $hook) {
            if (in_array($event, (array) ($hook['events'] ?? []), true) && ! empty($hook['url'])) {
                rescue(fn () => SendVoiceWebhook::dispatch($agentId, $hook['url'], $event, $data));
            }
        }
    }

    /**
     * POST one event to one URL. Returns [HTTP status or null, error or null] and records it in the agent's log.
     */
    public function send(VoiceAgent $agent, string $url, string $event, array $data, bool $test = false): array
    {
        $json = json_encode(array_filter([
            'event' => $event,
            'test' => $test ?: null,
            'sent_at' => now()->toIso8601String(),
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
        ]) + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout(15)->withHeaders([
                'User-Agent' => 'FlowForge-Webhooks',
                'X-FlowForge-Event' => $event,
                'X-FlowForge-Signature' => 'sha256='.hash_hmac('sha256', $json, self::secret($agent)),
            ])->withBody($json, 'application/json')->post($url);
            $result = [$response->status(), $response->successful() ? null : 'HTTP '.$response->status().': '.Str::limit(trim(strip_tags($response->body())), 140)];
        } catch (Throwable $e) {
            $result = [null, Str::limit($e->getMessage(), 200)];
        }

        $this->log($agent->id, ['at' => now()->toIso8601String(), 'event' => $event, 'url' => $url, 'status' => $result[0], 'error' => $result[1], 'test' => $test]);

        return $result;
    }

    /** The last 20 deliveries, newest first (kept in the cache: a log, not data). */
    public function recent(int $agentId): array
    {
        return Cache::get("voice-webhooks:{$agentId}", []);
    }

    private function log(int $agentId, array $entry): void
    {
        Cache::forever("voice-webhooks:{$agentId}", array_slice([$entry, ...$this->recent($agentId)], 0, 20));
    }

    public static function secret(VoiceAgent $agent): string
    {
        return (string) ($agent->setting('webhook_secret') ?: hash_hmac('sha256', 'voice-webhooks:'.$agent->id, (string) config('app.key')));
    }
}
