<?php

namespace App\Domain\Voice\Providers;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceCall;
use App\Domain\Voice\VoicePrompt;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** ElevenLabs Agents (elevenlabs.io): agents, a webhook tool for bookings, Twilio/SIP numbers and conversations. */
class ElevenLabsProvider implements VoiceProvider
{
    private const API = 'https://api.elevenlabs.io/v1/convai';

    /** "Rachel", a stock ElevenLabs voice available on every account. */
    private const DEFAULT_VOICE = '21m00Tcm4TlvDq8ikWAM';

    private function http(VoiceAgent $agent): PendingRequest
    {
        $key = $agent->credential?->data['api_key'] ?? null;
        if (! $key) {
            throw new RuntimeException('Connect your ElevenLabs account first (an ElevenLabs credential with your API key).');
        }

        return Http::baseUrl(self::API)->withHeaders(['xi-api-key' => $key])->acceptJson()->timeout(30);
    }

    private function check($response, string $what): array
    {
        if (! $response->successful()) {
            $detail = $response->json('detail');
            $message = is_array($detail) ? ($detail['message'] ?? collect($detail)->pluck('msg')->filter()->implode(' ') ?: json_encode($detail)) : $detail;
            throw new RuntimeException("ElevenLabs couldn't {$what}: ".($message ?: 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }

    public function sync(VoiceAgent $agent): void
    {
        // Bookings go through a webhook tool that calls FlowForge.
        $toolIds = [];
        if ($agent->bookingEnabled()) {
            $tool = VoicePrompt::bookingTool($agent);
            $config = ['tool_config' => ['type' => 'webhook', 'name' => $tool['name'], 'description' => $tool['description'], 'api_schema' => [
                'url' => $agent->eventsUrl(),
                'method' => 'POST',
                'request_body_schema' => ['type' => 'object', 'description' => 'The booking details agreed with the caller'] + $tool['parameters'],
            ]]];
            $response = $agent->remote_tool_id ? $this->http($agent)->patch("/tools/{$agent->remote_tool_id}", $config) : null;
            if (! $response || $response->status() === 404) {
                $response = $this->http($agent)->post('/tools', $config);
            }
            $agent->remote_tool_id = $this->check($response, 'save the booking tool')['id'];
            $toolIds[] = $agent->remote_tool_id;
        }

        $language = $agent->setting('language', 'en');
        $body = [
            'name' => $agent->name,
            'conversation_config' => [
                'agent' => [
                    'first_message' => VoicePrompt::fill($agent, (string) $agent->setting('first_message', '')),
                    'language' => $language,
                    'prompt' => ['prompt' => VoicePrompt::system($agent), 'llm' => $agent->setting('llm') ?: 'gemini-2.0-flash', 'tool_ids' => $toolIds],
                ],
                // Non-English agents need a multilingual voice model.
                'tts' => array_filter(['voice_id' => $agent->setting('voice') ?: self::DEFAULT_VOICE, 'model_id' => $language !== 'en' ? 'eleven_turbo_v2_5' : null]),
            ],
            'platform_settings' => ['data_collection' => (object) collect($agent->setting('collect', []))
                ->filter(fn ($f) => ! empty($f['key']))
                ->mapWithKeys(fn ($f) => [$f['key'] => ['type' => 'string', 'description' => (string) ($f['description'] ?? $f['key'])]])->all()],
        ];

        $response = $agent->remote_id ? $this->http($agent)->patch("/agents/{$agent->remote_id}", $body) : null;
        if (! $response || $response->status() === 404) {
            $response = $this->http($agent)->post('/agents/create', $body);
            $agent->remote_id = $this->check($response, 'create the agent')['agent_id'];
        } else {
            $this->check($response, 'save the agent');
        }
    }

    public function phoneNumbers(VoiceAgent $agent): array
    {
        return collect($this->check($this->http($agent)->get('/phone-numbers'), 'list your phone numbers'))
            ->map(fn ($n) => ['id' => $n['phone_number_id'], 'label' => trim(($n['phone_number'] ?? '').(! empty($n['label']) ? " ({$n['label']})" : '')), 'provider' => $n['provider'] ?? 'twilio'])
            ->values()->all();
    }

    public function attachNumber(VoiceAgent $agent): void
    {
        if ($agent->phone_number_id && $agent->remote_id) {
            $this->check($this->http($agent)->patch("/phone-numbers/{$agent->phone_number_id}", ['agent_id' => $agent->remote_id]), 'connect the phone number');
        }
    }

    public function call(VoiceAgent $agent, string $to, array $variables): string
    {
        if (! $agent->phone_number_id) {
            throw new RuntimeException('Choose the phone number this agent calls from (Phone tab).');
        }
        $number = collect($this->phoneNumbers($agent))->firstWhere('id', $agent->phone_number_id);
        $endpoint = ($number['provider'] ?? 'twilio') === 'sip_trunk' ? '/sip-trunk/outbound-call' : '/twilio/outbound-call';
        $result = $this->check($this->http($agent)->post($endpoint, array_filter([
            'agent_id' => $agent->remote_id,
            'agent_phone_number_id' => $agent->phone_number_id,
            'to_number' => $to,
            'conversation_initiation_client_data' => $variables ? ['dynamic_variables' => $variables] : null,
        ])), 'start the call');
        if (empty($result['conversation_id'])) {
            throw new RuntimeException("ElevenLabs didn't start the call: ".($result['message'] ?? 'no conversation was created'));
        }

        return $result['conversation_id'];
    }

    public function recentCalls(VoiceAgent $agent, CarbonInterface $since): array
    {
        $list = $this->check($this->http($agent)->get('/conversations', ['agent_id' => $agent->remote_id, 'call_start_after_unix' => $since->getTimestamp(), 'page_size' => 50]), 'list calls');
        $known = $agent->calls()->whereIn('status', VoiceCall::DONE)->pluck('remote_id')->filter()->flip();

        return collect($list['conversations'] ?? [])
            ->reject(fn ($c) => isset($known[$c['conversation_id']]))
            ->map(fn ($c) => in_array($c['status'], ['done', 'failed'], true)
                ? self::normalise($this->check($this->http($agent)->get("/conversations/{$c['conversation_id']}"), 'read a call'), $agent)
                : self::normalise($c, $agent))
            ->values()->all();
    }

    public function recording(VoiceCall $call): ?array
    {
        if (! $call->remote_id) {
            return null;
        }
        $response = $this->http($call->agent)->accept('audio/mpeg')->get("/conversations/{$call->remote_id}/audio");

        return $response->successful() ? ['body' => $response->body(), 'type' => $response->header('Content-Type') ?: 'audio/mpeg'] : null;
    }

    /** An ElevenLabs conversation (summary or full details) in FlowForge's shape. */
    public static function normalise(array $c, VoiceAgent $agent): array
    {
        $meta = (array) ($c['metadata'] ?? []);
        $phone = (array) ($meta['phone_call'] ?? []);
        $direction = $phone['direction'] ?? $c['direction'] ?? ($phone ? 'inbound' : 'web');
        $start = $meta['start_time_unix_secs'] ?? $c['start_time_unix_secs'] ?? null;
        $duration = $meta['call_duration_secs'] ?? $c['call_duration_secs'] ?? null;
        $analysis = (array) ($c['analysis'] ?? []);
        $status = match ($c['status'] ?? 'initiated') {
            'initiated' => 'queued',
            'in-progress', 'processing' => 'in-progress',
            'failed' => 'failed',
            default => 'completed',
        };

        return [
            'remote_id' => $c['conversation_id'],
            'direction' => in_array($direction, ['inbound', 'outbound'], true) ? $direction : 'web',
            'status' => $status,
            'from' => $direction === 'inbound' ? ($phone['external_number'] ?? null) : ($phone['agent_number'] ?? $agent->phone_label),
            'to' => $direction === 'inbound' ? ($phone['agent_number'] ?? $agent->phone_label) : ($phone['external_number'] ?? null),
            'started_at' => $start ? Carbon::createFromTimestamp($start) : null,
            'ended_at' => $start && $duration !== null ? Carbon::createFromTimestamp($start + $duration) : null,
            'duration' => $duration !== null ? (int) $duration : null,
            'cost' => null, // ElevenLabs reports credits, not money
            'summary' => $analysis['transcript_summary'] ?? null,
            'outcome' => VoicePrompt::outcome($analysis['call_successful'] ?? $c['call_successful'] ?? null),
            'transcript' => collect($c['transcript'] ?? [])
                ->filter(fn ($t) => trim((string) ($t['message'] ?? '')) !== '')
                ->map(fn ($t) => ['role' => ($t['role'] ?? '') === 'user' ? 'user' : 'agent', 'text' => trim($t['message']), 'at' => $t['time_in_call_secs'] ?? null])
                ->values()->all(),
            'data' => collect($analysis['data_collection_results'] ?? [])->map(fn ($r) => is_array($r) ? ($r['value'] ?? null) : $r)->filter(fn ($v) => $v !== null)->all() ?: null,
            'recording_url' => ! empty($c['has_audio']) ? 'provider' : null, // streamed through FlowForge (the API needs the key)
            'ended_reason' => null,
        ];
    }
}
