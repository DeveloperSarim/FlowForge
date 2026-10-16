<?php

namespace App\Domain\Voice;

use App\Domain\Assistant\LlmClient;
use App\Domain\Assistant\Providers;
use App\Domain\System\SystemSettings;
use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceBooking;
use App\Domain\Voice\Models\VoiceCall;
use App\Domain\Voice\Providers\ElevenLabsProvider;
use App\Domain\Voice\Providers\VapiProvider;
use App\Domain\Voice\Providers\VoiceProvider;
use App\Domain\Workflows\Models\Workflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Voice agents: saving them to their provider, placing calls, bookings made during calls, the free
 * browser conversation, keeping call history current (provider reports + a cron poll) and starting
 * the workflows that listen for ended calls and new bookings.
 */
class VoiceService
{
    public function __construct(private SystemSettings $settings, private LlmClient $llm) {}

    public function provider(VoiceAgent $agent): VoiceProvider
    {
        return match ($agent->provider) {
            'vapi' => app(VapiProvider::class),
            'elevenlabs' => app(ElevenLabsProvider::class),
            default => throw new RuntimeException('Browser agents have no phone provider. Choose Vapi or ElevenLabs on the Phone tab to make real calls.'),
        };
    }

    /** @return list<array> the ready-made agents in resources/voice-templates */
    public function templates(): array
    {
        static $templates;

        return $templates ??= collect(File::glob(resource_path('voice-templates/*.json')))
            ->map(fn ($f) => json_decode((string) file_get_contents($f), true))->filter()->sortBy('name')->values()->all();
    }

    public function create(string $name, ?string $template = null, array $config = []): VoiceAgent
    {
        $t = $template ? collect($this->templates())->firstWhere('key', $template) : null;
        $config = array_replace_recursive([
            'business_name' => '', 'persona_name' => '', 'tone' => 'warm', 'verbosity' => 'balanced', 'first_message' => 'Hello! How can I help you today?',
            'instructions' => 'You are a helpful phone assistant.', 'knowledge' => '', 'faqs' => [], 'language' => 'en', 'voice' => '', 'browser_voice' => '', 'llm' => '',
            'booking' => ['enabled' => false, 'ask_email' => false, 'hours' => '', 'duration' => 30, 'services' => ''], 'collect' => [],
        ], $t ? array_intersect_key($t, array_flip(['first_message', 'instructions', 'language', 'booking', 'collect', 'persona_name', 'tone', 'verbosity', 'knowledge', 'faqs'])) + ['template' => $t['key']] : [], $config);

        return VoiceAgent::query()->create(['name' => Str::limit(trim($name) ?: ($t['name'] ?? 'Voice agent'), 120, ''), 'provider' => 'browser', 'config' => $config]);
    }

    /** Pushes the agent to its provider. Returns the problem, or null when it's live there. */
    public function publish(VoiceAgent $agent): ?string
    {
        if (! $agent->isPhone()) {
            return null;
        }
        try {
            $provider = $this->provider($agent);
            $provider->sync($agent);
            $provider->attachNumber($agent); // inbound calls on the chosen number reach this agent
            $agent->forceFill(['synced_at' => now(), 'sync_error' => null])->save();

            return null;
        } catch (RuntimeException $e) {
            $agent->forceFill(['sync_error' => $e->getMessage()])->save();

            return $e->getMessage();
        }
    }

    public function call(VoiceAgent $agent, string $to, array $variables = []): VoiceCall
    {
        $to = preg_replace('/[\s().-]/', '', $to);
        if (! preg_match('/^\+[1-9]\d{6,14}$/', $to)) {
            throw new RuntimeException('Use the international format for the number, with + and the country code, e.g. +14155550123 or +923001234567.');
        }
        if (! $agent->isPhone()) {
            throw new RuntimeException('This agent only talks in the browser. Choose Vapi or ElevenLabs on its Phone tab to make real calls.');
        }
        if (! $agent->remote_id && ($problem = $this->publish($agent))) {
            throw new RuntimeException($problem);
        }
        $variables = array_map('strval', array_filter($variables, fn ($v) => is_scalar($v) && $v !== ''));
        $remote = $this->provider($agent)->call($agent, $to, $variables);

        return $agent->calls()->create(['remote_id' => $remote, 'direction' => 'outbound', 'status' => 'queued', 'from_number' => $agent->phone_label, 'to_number' => $to,
            'data' => $variables ? ['variables' => $variables] : null]);
    }

    /**
     * The booking tool. Interprets the time in FlowForge's timezone and answers in words the agent can read out.
     *
     * @return array{ok: bool, message: string, booking?: VoiceBooking}
     */
    public function book(VoiceAgent $agent, array $args, ?VoiceCall $call = null): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        $when = trim((string) ($args['date_time'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => "I still need the caller's name before I can book."];
        }
        if ($when === '') {
            return ['ok' => false, 'message' => 'I still need the date and time the caller wants.'];
        }
        $timezone = $this->settings->timezone();
        try {
            $starts = Carbon::parse($when, $timezone);
        } catch (Throwable) {
            $starts = null;
        }
        if ($starts && $starts->lt(now($timezone)->subMinutes(5))) {
            return ['ok' => false, 'message' => 'That time has already passed. Ask the caller for a future date and time.'];
        }

        $existing = $agent->bookings()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($starts, fn ($q) => $q->where('starts_at', $starts->copy()->utc()), fn ($q) => $q->where('requested_time', $when))->first();
        $booking = $existing ?? $agent->bookings()->create([
            'voice_call_id' => $call?->id,
            'name' => Str::limit($name, 250, ''),
            'phone' => Str::limit(trim((string) ($args['phone'] ?? '')) ?: ($call?->direction === 'inbound' ? $call->from_number : $call?->to_number), 40, '') ?: null,
            'email' => Str::limit(trim((string) ($args['email'] ?? '')), 250, '') ?: null,
            'service' => Str::limit(trim((string) ($args['service'] ?? '')), 250, '') ?: null,
            'starts_at' => $starts?->copy()->utc(),
            'requested_time' => Str::limit($when, 250, ''),
            'notes' => trim((string) ($args['notes'] ?? '')) ?: null,
        ]);
        if (! $existing) {
            $this->startWorkflows('voice_booking', $agent->id, ['booking' => $this->bookingPayload($booking)]);
        }

        return ['ok' => true, 'booking' => $booking, 'message' => $starts
            ? "Booked for {$booking->name} on {$starts->format('l, j F')} at {$starts->format('g:i A')}. Reference number {$booking->id}."
            : "I've noted {$booking->name}'s request for {$when}. The team will confirm the exact time. Reference number {$booking->id}."];
    }

    /** What a provider sends to the agent's events URL: Vapi tool calls and call reports, ElevenLabs tool calls. */
    public function handleEvent(VoiceAgent $agent, array $payload): array
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;

        if (($message['type'] ?? null) === 'tool-calls') {
            $call = $this->upsertCall($agent, VapiProvider::normalise(($message['call'] ?? []) + ['status' => 'in-progress'], $agent));

            return ['results' => array_map(function ($tc) use ($agent, $call) {
                $args = $tc['function']['arguments'] ?? [];
                $args = is_string($args) ? (json_decode($args, true) ?: []) : (array) $args;
                if (($tc['function']['name'] ?? null) !== 'book_appointment') {
                    return ['toolCallId' => $tc['id'] ?? '', 'error' => 'Unknown tool.'];
                }

                return ['toolCallId' => $tc['id'] ?? '', 'result' => $this->book($agent, $args, $call)['message']];
            }, $message['toolCallList'] ?? [])];
        }

        if (($message['type'] ?? null) === 'end-of-call-report' && ! empty($message['call']['id'])) {
            $report = array_filter(['artifact' => $message['artifact'] ?? null, 'analysis' => $message['analysis'] ?? null, 'endedReason' => $message['endedReason'] ?? null,
                'startedAt' => $message['startedAt'] ?? null, 'endedAt' => $message['endedAt'] ?? null, 'cost' => $message['cost'] ?? null]);
            $this->upsertCall($agent, VapiProvider::normalise(['status' => 'ended'] + $report + $message['call'], $agent));

            return ['ok' => true];
        }

        if ($message === null && (isset($payload['name']) || isset($payload['date_time']))) { // an ElevenLabs webhook tool sends the arguments as the body
            $result = $this->book($agent, $payload);

            return ['result' => $result['message'], 'booked' => $result['ok']];
        }

        return ['ok' => true];
    }

    /** Creates or updates a call from a normalised provider call; starts workflows the first time it is done. */
    public function upsertCall(VoiceAgent $agent, array $n): VoiceCall
    {
        $call = $agent->calls()->where('remote_id', $n['remote_id'])->first() ?? $agent->calls()->make(['remote_id' => $n['remote_id']]);
        $wasDone = $call->exists && $call->isDone();
        $fields = ['direction' => $n['direction'], 'status' => $n['status'], 'from_number' => $n['from'], 'to_number' => $n['to'], 'started_at' => $n['started_at'],
            'ended_at' => $n['ended_at'], 'duration_seconds' => $n['duration'], 'cost' => $n['cost'], 'summary' => $n['summary'], 'outcome' => $n['outcome'],
            'transcript' => $n['transcript'] ?: null, 'recording_url' => $n['recording_url'], 'ended_reason' => $n['ended_reason']];
        // A tool call arrives mid-call with less detail than the final report: never blank out what's known,
        // and never move a finished call back to "in progress".
        if ($wasDone && ! in_array($n['status'], VoiceCall::DONE, true)) {
            unset($fields['status']);
        }
        $call->fill(array_filter($fields, fn ($v) => $v !== null));
        if ($n['data']) {
            $call->data = array_merge((array) $call->data, $n['data']);
        }
        $call->save();
        if (! $wasDone && $call->isDone()) {
            $this->startWorkflows('voice_call', $agent->id, ['call' => $this->callPayload($call)]);
        }

        return $call;
    }

    /** Every minute from cron: catches up on finished calls (ElevenLabs only reports this way; Vapi also reports live). */
    public function poll(): int
    {
        $updated = 0;
        VoiceAgent::query()->where('provider', '!=', 'browser')->whereNotNull('remote_id')->whereNotNull('credential_id')->with('credential')->each(function (VoiceAgent $agent) use (&$updated) {
            $pending = $agent->calls()->whereNotIn('status', VoiceCall::DONE)->where('created_at', '>', now()->subDay())->exists();
            if (! $pending && $agent->polled_at && $agent->polled_at->gt(now()->subMinutes(5))) {
                return;
            }
            $since = ($agent->calls()->whereNotNull('started_at')->latest('started_at')->value('started_at') ?? now()->subDays(3))->copy()->subHours(2);
            try {
                foreach ($this->provider($agent)->recentCalls($agent, $since) as $n) {
                    $this->upsertCall($agent, $n);
                    $updated++;
                }
            } catch (Throwable $e) {
                report($e);
            }
            // Calls the provider never reported on.
            $agent->calls()->whereNotIn('status', VoiceCall::DONE)->where('direction', '!=', 'web')->where('created_at', '<', now()->subHours(6))
                ->update(['status' => 'failed', 'ended_reason' => 'No report from the provider']);
            $agent->forceFill(['polled_at' => now()])->saveQuietly();
        });

        return $updated;
    }

    // ---------- The free browser conversation ----------

    private function aiConfig(): array
    {
        $config = (array) $this->settings->get('ai', []);
        if (! Providers::configured($config)) {
            throw new RuntimeException('Talking in the browser uses the AI model from Settings → AI builder. Add a provider and key there first (Google Gemini and DeepSeek have free or very cheap tiers).');
        }

        return $config;
    }

    /**
     * One turn of a browser conversation. With an empty $text and no call yet, the agent says its first message.
     *
     * @return array{call: VoiceCall, reply: string, booking: ?VoiceBooking}
     */
    public function talk(VoiceAgent $agent, ?VoiceCall $call, string $text, array $variables = []): array
    {
        $config = $this->aiConfig();
        $call ??= $agent->calls()->create(['direction' => 'web', 'status' => 'in-progress', 'started_at' => now(), 'context' => ['history' => [], 'variables' => $variables]]);
        $variables = $call->context['variables'] ?? $variables;
        $transcript = $call->transcript ?? [];
        $at = fn () => round(abs($call->started_at->diffInMilliseconds(now())) / 1000, 1);
        $greeting = trim(VoicePrompt::fill($agent, (string) $agent->setting('first_message', ''), $variables));

        if (trim($text) === '' && $transcript === []) {
            $reply = $greeting !== '' ? $greeting : '';
            if ($reply !== '') {
                $call->update(['transcript' => [['role' => 'agent', 'text' => $reply, 'at' => 0]]]);
            }

            return ['call' => $call, 'reply' => $reply, 'booking' => null];
        }

        $history = $call->context['history'] ?? [];
        $this->llm->addUser($config, $history, Str::limit(trim($text), 2000, ''));
        $transcript[] = ['role' => 'user', 'text' => trim($text), 'at' => $at()];

        $timezone = $this->settings->timezone();
        $system = VoicePrompt::system($agent, $variables)."\n\nToday is ".now($timezone)->format('l, j F Y, g:i A')." ({$timezone})."
            .($greeting !== '' ? "\nYou already greeted the caller with: \"{$greeting}\"" : '');
        $tools = array_values(array_filter([$agent->bookingEnabled() ? VoicePrompt::bookingTool($agent) : null, VoicePrompt::endCallTool()]));
        $said = [];
        $booking = null;
        $ended = false;
        for ($step = 0; $step < 3 && ! $ended; $step++) {
            $turn = $this->llm->turn($config, $system, $tools, $history, 600);
            $said[] = trim($turn->text); // text that comes with a tool call is spoken too ("Booking that now…", "Goodbye!")
            if (! $turn->toolCalls) {
                break;
            }
            $results = [];
            foreach ($turn->toolCalls as $tc) {
                $ended = $ended || $tc['name'] === 'end_call';
                $result = match ($tc['name']) {
                    'book_appointment' => $this->book($agent, $tc['input'], $call),
                    'end_call' => ['ok' => true, 'message' => 'The call has ended.'],
                    default => ['ok' => false, 'message' => 'Unknown tool.'],
                };
                $booking = $result['booking'] ?? $booking;
                $results[] = ['id' => $tc['id'], 'name' => $tc['name'], 'content' => $result['message'], 'is_error' => ! $result['ok']];
            }
            $this->llm->addToolResults($config, $history, $results);
        }
        $reply = implode(' ', array_filter($said));
        $reply = $reply !== '' || $ended ? $reply : 'Sorry, could you say that again?';
        if ($reply !== '') {
            $transcript[] = ['role' => 'agent', 'text' => $reply, 'at' => $at()];
        }
        $call->update(['transcript' => $transcript, 'context' => ['history' => $history, 'variables' => $variables]]);

        return ['call' => $call, 'reply' => $reply, 'booking' => $booking, 'ended' => $ended];
    }

    /** Ends a browser conversation: summary, outcome and the details to collect, then the workflows. */
    public function endTalk(VoiceCall $call): VoiceCall
    {
        if ($call->isDone()) {
            return $call;
        }
        $call->fill(['status' => 'completed', 'ended_at' => now(), 'duration_seconds' => $call->started_at ? (int) abs(now()->diffInSeconds($call->started_at)) : null]);
        if (collect($call->transcript)->contains('role', 'user')) {
            try {
                $config = $this->aiConfig();
                $fields = collect($call->agent->setting('collect', []))->pluck('description', 'key')->filter();
                $history = [];
                $this->llm->addUser($config, $history, "Call transcript:\n".$call->transcriptText());
                $answer = $this->llm->turn($config, 'You summarise phone calls. Reply with JSON only: {"summary": "two sentences", "outcome": "success|failure|unknown" (did the call achieve its goal?), "data": {'
                    .$fields->map(fn ($d, $k) => "\"{$k}\": \"{$d}\"")->implode(', ').'}}. Use null for details the caller never gave.', [], $history, 800)->text;
                $json = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($answer)), true);
                if (is_array($json)) {
                    $call->fill(['summary' => $json['summary'] ?? null, 'outcome' => VoicePrompt::outcome($json['outcome'] ?? null) ?? 'unknown',
                        'data' => array_filter((array) ($json['data'] ?? []), fn ($v) => $v !== null && $v !== '') ?: null]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $call->context = null;
        $call->save();
        $this->startWorkflows('voice_call', $call->voice_agent_id, ['call' => $this->callPayload($call)]);

        return $call;
    }

    /** "Write it with AI": turns a description into a complete agent. */
    public function generate(string $description): array
    {
        $config = $this->aiConfig();
        $example = collect($this->templates())->firstWhere('key', 'clinic-receptionist');
        $history = [];
        $this->llm->addUser($config, $history, "Describe the voice agent to build:\n".Str::limit(trim($description), 3000, ''));
        $text = $this->llm->turn($config, <<<PROMPT
You design AI phone agents for small businesses. Reply with JSON only, in this shape:
{"name": "short agent name", "business_name": "the business, if mentioned, else empty", "direction": "inbound or outbound",
 "first_message": "what the agent says first; may use {{customer_name}} for outbound calls",
 "instructions": "clear instructions in the second person: role, goals, tone, rules, what to do when unsure. Plain text, short paragraphs and dashes, no markdown headings.",
 "language": "ISO code like en, ur, hi, ar, es",
 "booking": {"enabled": true or false, "ask_email": true or false, "hours": "opening hours if known, else empty"},
 "collect": [{"key": "snake_case", "description": "a detail to capture from each call"}]}
Write {{business_name}} wherever the business name belongs. Turn booking on only when the agent schedules appointments, reservations, viewings or calls.
Here is an example of a good agent: {$this->exampleJson($example)}
PROMPT, [], $history, 3000)->text;
        $json = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($text)), true);
        if (! is_array($json) || empty($json['instructions'])) {
            throw new RuntimeException("The AI didn't return a usable agent. Try describing it in a little more detail.");
        }

        return [
            'name' => Str::limit((string) ($json['name'] ?? 'Voice agent'), 120, ''),
            'business_name' => (string) ($json['business_name'] ?? ''),
            'first_message' => (string) ($json['first_message'] ?? ''),
            'instructions' => (string) $json['instructions'],
            'language' => preg_match('/^[a-z]{2}$/', (string) ($json['language'] ?? '')) ? $json['language'] : 'en',
            'booking' => ['enabled' => (bool) data_get($json, 'booking.enabled'), 'ask_email' => (bool) data_get($json, 'booking.ask_email'), 'hours' => (string) data_get($json, 'booking.hours', '')],
            'collect' => collect($json['collect'] ?? [])->filter(fn ($f) => is_array($f) && ! empty($f['key']))
                ->map(fn ($f) => ['key' => Str::snake(Str::limit((string) $f['key'], 40, '')), 'description' => (string) ($f['description'] ?? '')])->values()->take(8)->all(),
        ];
    }

    private function exampleJson(?array $t): string
    {
        return json_encode($t ? array_intersect_key($t, array_flip(['name', 'first_message', 'instructions', 'booking', 'collect'])) : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ---------- Workflows ----------

    /** Starts active workflows with this trigger type whose "Voice agent" is empty or this agent. */
    private function startWorkflows(string $type, int $agentId, array $data): void
    {
        app(VoiceWebhooks::class)->dispatch($type, $agentId, $data); // the agent's own webhooks, no workflow needed
        Workflow::query()->active()->where('trigger_type', $type)->each(function (Workflow $workflow) use ($agentId, $data) {
            $only = $workflow->triggerConfig()['agent_id'] ?? null;
            if ($only === null || $only === '' || (int) $only === $agentId) {
                rescue(fn () => $workflow->start('voice', ['data' => $data]));
            }
        });
    }

    public function callPayload(VoiceCall $call): array
    {
        return [
            'id' => $call->id, 'agent' => ['id' => $call->voice_agent_id, 'name' => $call->agent?->name],
            'direction' => $call->direction, 'status' => $call->status, 'from' => $call->from_number, 'to' => $call->to_number,
            'started_at' => $call->started_at?->toIso8601String(), 'duration_seconds' => $call->duration_seconds,
            'summary' => $call->summary, 'outcome' => $call->outcome, 'data' => $call->data ?? (object) [],
            'transcript' => $call->transcriptText(), 'recording_url' => $call->recording_url ? url("/voice/calls/{$call->id}/recording") : null,
            'url' => url("/voice/calls?open={$call->id}"),
        ];
    }

    public function bookingPayload(VoiceBooking $b): array
    {
        $timezone = $this->settings->timezone();

        return [
            'id' => $b->id, 'agent' => ['id' => $b->voice_agent_id, 'name' => $b->agent?->name], 'call_id' => $b->voice_call_id,
            'name' => $b->name, 'phone' => $b->phone, 'email' => $b->email, 'service' => $b->service, 'notes' => $b->notes,
            'starts_at' => $b->starts_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $b->starts_at?->copy()->setTimezone($timezone)->addMinutes((int) ($b->agent?->setting('booking.duration', 30) ?: 30))->toIso8601String(),
            'when' => $b->starts_at ? $b->starts_at->copy()->setTimezone($timezone)->format('l, j F Y \a\t g:i A') : $b->requested_time,
            'requested_time' => $b->requested_time, 'url' => url('/voice/bookings'),
        ];
    }
}
