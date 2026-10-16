<?php

namespace App\Domain\Voice\Providers;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceCall;
use App\Domain\Voice\VoicePrompt;
use App\Domain\Voice\VoiceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Vapi (vapi.ai): assistants, phone numbers and calls through its REST API. */
class VapiProvider implements VoiceProvider
{
    private const API = 'https://api.vapi.ai';

    private function http(VoiceAgent $agent): PendingRequest
    {
        $key = $agent->credential?->data['token'] ?? null;
        if (! $key) {
            throw new RuntimeException('Connect your Vapi account first (a Vapi credential with your private API key).');
        }

        return Http::baseUrl(self::API)->withToken($key)->acceptJson()->timeout(30);
    }

    private function check($response, string $what): array
    {
        if (! $response->successful()) {
            $message = $response->json('message');
            throw new RuntimeException("Vapi couldn't {$what}: ".(is_array($message) ? implode(' ', $message) : ($message ?: 'HTTP '.$response->status())));
        }

        return (array) $response->json();
    }

    public function sync(VoiceAgent $agent): void
    {
        $voice = (string) $agent->setting('voice', '');
        // Vapi sends these headers with every tool call and report (Server.headers in its API).
        $server = ['url' => $agent->eventsUrl(), 'headers' => [VoiceService::EVENTS_HEADER => VoiceService::eventsSecret($agent)]];
        $body = [
            'name' => mb_substr($agent->name, 0, 40),
            'firstMessage' => VoicePrompt::fill($agent, (string) $agent->setting('first_message', '')),
            'firstMessageMode' => trim((string) $agent->setting('first_message', '')) !== '' ? 'assistant-speaks-first' : 'assistant-waits-for-user',
            'model' => array_filter([
                'provider' => 'openai',
                'model' => $agent->setting('llm') ?: 'gpt-4o-mini',
                'messages' => [['role' => 'system', 'content' => VoicePrompt::system($agent)]],
                'tools' => array_values(array_filter([
                    $agent->bookingEnabled() ? ['type' => 'function', 'function' => VoicePrompt::bookingTool($agent), 'server' => $server] : null,
                    ['type' => 'endCall'], // lets the assistant hang up after goodbye
                ])),
            ]),
            // Vapi's own voices are names (Elliot, Kylie…); a long id is an ElevenLabs voice.
            'voice' => strlen($voice) >= 20 ? ['provider' => '11labs', 'voiceId' => $voice] : ['provider' => 'vapi', 'voiceId' => $voice ?: 'Elliot'],
            'server' => $server,
            'serverMessages' => ['end-of-call-report', 'tool-calls'],
            'artifactPlan' => ['recordingEnabled' => true],
        ];
        if (($language = $agent->setting('language', 'en')) !== 'en') {
            $body['transcriber'] = ['provider' => 'deepgram', 'model' => 'nova-2', 'language' => $language];
        }
        if ($fields = VoicePrompt::collectSchema($agent)) {
            $body['analysisPlan'] = ['structuredDataPlan' => ['enabled' => true, 'schema' => $fields]];
        }

        $response = $agent->remote_id
            ? $this->http($agent)->patch("/assistant/{$agent->remote_id}", $body)
            : $this->http($agent)->post('/assistant', $body);
        if ($agent->remote_id && $response->status() === 404) {
            $response = $this->http($agent)->post('/assistant', $body); // deleted on Vapi: create it again
        }
        $agent->remote_id = $this->check($response, 'save the assistant')['id'];
    }

    public function phoneNumbers(VoiceAgent $agent): array
    {
        return collect($this->check($this->http($agent)->get('/phone-number', ['limit' => 100]), 'list your phone numbers'))
            ->map(fn ($n) => ['id' => $n['id'], 'label' => trim(($n['number'] ?? $n['sipUri'] ?? '').(! empty($n['name']) ? " ({$n['name']})" : '')) ?: $n['id']])
            ->values()->all();
    }

    public function attachNumber(VoiceAgent $agent): void
    {
        if ($agent->phone_number_id && $agent->remote_id) {
            $this->check($this->http($agent)->patch("/phone-number/{$agent->phone_number_id}", ['assistantId' => $agent->remote_id]), 'connect the phone number');
        }
    }

    public function call(VoiceAgent $agent, string $to, array $variables): string
    {
        if (! $agent->phone_number_id) {
            throw new RuntimeException('Choose the phone number this agent calls from (Phone tab).');
        }

        return $this->check($this->http($agent)->post('/call', array_filter([
            'assistantId' => $agent->remote_id,
            'phoneNumberId' => $agent->phone_number_id,
            'customer' => ['number' => $to],
            'assistantOverrides' => $variables ? ['variableValues' => $variables] : null,
        ])), 'start the call')['id'];
    }

    public function recentCalls(VoiceAgent $agent, CarbonInterface $since): array
    {
        $calls = $this->check($this->http($agent)->get('/call', ['assistantId' => $agent->remote_id, 'createdAtGt' => $since->toIso8601ZuluString(), 'limit' => 100]), 'list calls');

        return array_map(fn ($c) => self::normalise($c, $agent), array_values($calls));
    }

    public function recording(VoiceCall $call): ?array
    {
        return $call->recording_url ? ['url' => $call->recording_url] : null;
    }

    /** A Vapi call object (from the API or an end-of-call report) in FlowForge's shape. */
    public static function normalise(array $c, VoiceAgent $agent): array
    {
        $artifact = (array) ($c['artifact'] ?? []);
        $analysis = (array) ($c['analysis'] ?? []);
        $reason = (string) ($c['endedReason'] ?? '');
        $status = match ($c['status'] ?? 'queued') {
            'scheduled', 'queued' => 'queued',
            'ringing' => 'ringing',
            'in-progress', 'forwarding' => 'in-progress',
            default => preg_match('/no-answer|did-not-answer|busy|voicemail/', $reason) ? 'no-answer'
                : (preg_match('/error|fail|could-not|unknown/', $reason) ? 'failed' : 'completed'),
        };
        $direction = match ($c['type'] ?? '') {
            'inboundPhoneCall' => 'inbound',
            'webCall' => 'web',
            default => 'outbound',
        };
        $ours = $c['phoneNumber']['number'] ?? $agent->phone_label;
        $customer = $c['customer']['number'] ?? null;
        $started = isset($c['startedAt']) ? Carbon::parse($c['startedAt']) : null;
        $ended = isset($c['endedAt']) ? Carbon::parse($c['endedAt']) : null;
        $success = $analysis['successEvaluation'] ?? null;

        return [
            'remote_id' => $c['id'],
            'direction' => $direction,
            'status' => $status,
            'from' => $direction === 'inbound' ? $customer : $ours,
            'to' => $direction === 'inbound' ? $ours : $customer,
            'started_at' => $started,
            'ended_at' => $ended,
            'duration' => $started && $ended ? max(0, (int) $started->diffInSeconds($ended)) : null,
            'cost' => isset($c['cost']) ? (float) $c['cost'] : null,
            'summary' => $analysis['summary'] ?? null,
            'outcome' => VoicePrompt::outcome($success),
            'transcript' => collect($artifact['messages'] ?? $c['messages'] ?? [])
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['assistant', 'bot', 'user'], true) && trim((string) ($m['message'] ?? '')) !== '')
                ->map(fn ($m) => ['role' => $m['role'] === 'user' ? 'user' : 'agent', 'text' => trim($m['message']), 'at' => isset($m['secondsFromStart']) ? round((float) $m['secondsFromStart'], 1) : null])
                ->values()->all(),
            'data' => is_array($analysis['structuredData'] ?? null) ? $analysis['structuredData'] : null,
            'recording_url' => $artifact['recordingUrl'] ?? data_get($artifact, 'recording.mono.combinedUrl') ?? data_get($artifact, 'recording.combinedUrl')
                ?? $artifact['stereoRecordingUrl'] ?? $c['recordingUrl'] ?? null,
            'ended_reason' => $reason ?: null,
        ];
    }
}
