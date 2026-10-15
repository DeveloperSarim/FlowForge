<?php

namespace App\Domain\Voice\Controllers;

use App\Domain\Assistant\Providers;
use App\Domain\Integrations\Models\Credential;
use App\Domain\System\SystemSettings;
use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceBooking;
use App\Domain\Voice\Models\VoiceCall;
use App\Domain\Voice\Providers\ElevenLabsProvider;
use App\Domain\Voice\VoicePrompt;
use App\Domain\Voice\VoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Voice agents: list, editor, calls, bookings, and the browser conversation. */
class VoiceController extends Controller
{
    public function __construct(private VoiceService $voice, private SystemSettings $settings) {}

    private function hasAi(): bool
    {
        $ai = (array) $this->settings->get('ai', []);

        return Providers::configured($ai);
    }

    public function index(): Response
    {
        $agents = VoiceAgent::query()->withCount(['calls', 'bookings'])->withMax('calls', 'created_at')->latest()->get();

        return Inertia::render('Voice/Agents', [
            'agents' => $agents->map(fn (VoiceAgent $a) => [
                'id' => $a->id, 'name' => $a->name, 'provider' => $a->provider, 'phone' => $a->phone_label, 'calls' => $a->calls_count, 'bookings' => $a->bookings_count,
                'last_call' => $a->calls_max_created_at, 'problem' => $a->sync_error, 'template' => $a->setting('template'), 'booking' => $a->bookingEnabled(),
                'persona' => $a->setting('persona_name'), 'business' => $a->setting('business_name'), 'live' => (bool) $a->remote_id,
            ]),
            'templates' => collect($this->voice->templates())->map(fn ($t) => Arr::only($t, ['key', 'name', 'description', 'icon', 'direction', 'category', 'persona_name', 'sample', 'booking', 'collect', 'tone', 'questions']))->values(),
            'stats' => [
                'calls_today' => VoiceCall::query()->where('created_at', '>=', now()->startOfDay())->count(),
                'minutes_week' => (int) round(VoiceCall::query()->where('created_at', '>=', now()->subDays(7))->sum('duration_seconds') / 60),
                'upcoming' => VoiceBooking::query()->where('status', 'booked')->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))->count(),
            ],
            'hasAi' => $this->hasAi(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'template' => ['nullable', 'string', 'max:80'],
            'config' => ['nullable', 'array'],
        ]);
        $agent = $this->voice->create((string) ($data['name'] ?? ''), $data['template'] ?? null, $this->cleanConfig((array) $request->input('config', [])));

        return redirect("/voice/agents/{$agent->id}");
    }

    public function generate(Request $request): JsonResponse
    {
        $description = $request->validate(['description' => ['required', 'string', 'min:10', 'max:3000']])['description'];
        try {
            return response()->json(['agent' => $this->voice->generate($description)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(VoiceAgent $agent): Response
    {
        return Inertia::render('Voice/Agent', [
            'agent' => [
                'id' => $agent->id, 'name' => $agent->name, 'provider' => $agent->provider, 'credential_id' => $agent->credential_id, 'config' => $agent->config,
                'phone_number_id' => $agent->phone_number_id, 'phone_label' => $agent->phone_label, 'live' => (bool) $agent->remote_id,
                'synced_at' => $agent->synced_at?->toIso8601String(), 'problem' => $agent->sync_error,
            ],
            'credentials' => Credential::query()->whereIn('integration', array_values(VoiceAgent::CREDENTIAL_APP))->orderBy('name')->get(['id', 'name', 'integration']),
            'variables' => VoicePrompt::variables($agent),
            'questions' => collect($this->voice->templates())->firstWhere('key', $agent->setting('template'))['questions'] ?? [],
            'recentCalls' => $agent->calls()->latest()->limit(8)->get()->map(fn (VoiceCall $c) => $this->callRow($c)),
            'hasAi' => $this->hasAi(),
            'timezone' => $this->settings->timezone(),
        ]);
    }

    public function update(Request $request, VoiceAgent $agent): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(VoiceAgent::PROVIDERS)],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'phone_number_id' => ['nullable', 'string', 'max:120'],
            'phone_label' => ['nullable', 'string', 'max:120'],
            'config' => ['required', 'array'],
            'config.instructions' => ['required', 'string', 'max:20000'],
        ]);
        if ($data['provider'] !== 'browser' && $data['credential_id']) {
            $app = Credential::query()->whereKey($data['credential_id'])->value('integration');
            if ($app !== VoiceAgent::CREDENTIAL_APP[$data['provider']]) {
                return back()->with('error', 'That credential is not for '.($data['provider'] === 'vapi' ? 'Vapi' : 'ElevenLabs').'.');
            }
        }
        // validate() keeps only config.instructions from the nested array; the rest is cleaned key by key here.
        $agent->fill([...$data, 'config' => $this->cleanConfig((array) $request->input('config')) + ['template' => $agent->setting('template')]]);
        if ($data['provider'] === 'browser') {
            $agent->fill(['credential_id' => null, 'phone_number_id' => null, 'phone_label' => null]);
        }
        if ($agent->isDirty('provider')) {
            $agent->forceFill(['remote_id' => null, 'remote_tool_id' => null, 'synced_at' => null, 'sync_error' => null]);
        }
        $agent->save();

        if ($agent->isPhone() && $agent->credential_id) {
            $problem = $this->voice->publish($agent);

            return back()->with($problem ? 'error' : 'success', $problem ?? 'Saved, and the agent is live on '.($agent->provider === 'vapi' ? 'Vapi' : 'ElevenLabs').'.');
        }

        return back()->with('success', 'Saved.');
    }

    /** The settings the editor sends, with only the keys an agent uses. */
    private function cleanConfig(array $c): array
    {
        return array_filter([
            'business_name' => isset($c['business_name']) ? mb_substr((string) $c['business_name'], 0, 160) : null,
            'persona_name' => isset($c['persona_name']) ? mb_substr(trim((string) $c['persona_name']), 0, 60) : null,
            'tone' => isset($c['tone']) && array_key_exists($c['tone'], VoicePrompt::TONES) ? $c['tone'] : null,
            'verbosity' => isset($c['verbosity']) && in_array($c['verbosity'], ['short', 'balanced', 'detailed'], true) ? $c['verbosity'] : null,
            'knowledge' => isset($c['knowledge']) ? mb_substr((string) $c['knowledge'], 0, 20000) : null,
            'faqs' => isset($c['faqs']) ? collect((array) $c['faqs'])->filter(fn ($f) => is_array($f) && (trim((string) ($f['q'] ?? '')) !== '' || trim((string) ($f['a'] ?? '')) !== ''))
                ->map(fn ($f) => ['q' => mb_substr((string) ($f['q'] ?? ''), 0, 500), 'a' => mb_substr((string) ($f['a'] ?? ''), 0, 2000)])->values()->take(40)->all() : null,
            'browser_voice' => isset($c['browser_voice']) ? mb_substr((string) $c['browser_voice'], 0, 120) : null,
            'first_message' => isset($c['first_message']) ? mb_substr((string) $c['first_message'], 0, 1000) : null,
            'instructions' => isset($c['instructions']) ? mb_substr((string) $c['instructions'], 0, 20000) : null,
            'language' => isset($c['language']) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', (string) $c['language']) ? $c['language'] : null,
            'voice' => isset($c['voice']) ? mb_substr(trim((string) $c['voice']), 0, 80) : null,
            'voice_name' => isset($c['voice_name']) ? mb_substr(trim((string) $c['voice_name']), 0, 120) : null,
            'llm' => isset($c['llm']) ? mb_substr(trim((string) $c['llm']), 0, 80) : null,
            'booking' => isset($c['booking']) ? ['enabled' => (bool) ($c['booking']['enabled'] ?? false), 'ask_email' => (bool) ($c['booking']['ask_email'] ?? false),
                'hours' => mb_substr((string) ($c['booking']['hours'] ?? ''), 0, 200), 'duration' => max(5, min(480, (int) ($c['booking']['duration'] ?? 30))),
                'services' => mb_substr((string) ($c['booking']['services'] ?? ''), 0, 500)] : null,
            'collect' => isset($c['collect']) ? collect((array) $c['collect'])->filter(fn ($f) => is_array($f) && trim((string) ($f['key'] ?? '')) !== '')
                ->map(fn ($f) => ['key' => Str::snake(mb_substr(trim((string) $f['key']), 0, 40)), 'description' => mb_substr((string) ($f['description'] ?? ''), 0, 300)])->values()->take(12)->all() : null,
        ], fn ($v) => $v !== null);
    }

    public function destroy(VoiceAgent $agent): RedirectResponse
    {
        $agent->delete();

        return redirect('/voice')->with('success', "Deleted {$agent->name}. Its calls and bookings were removed too.");
    }

    /** Voices to choose from: Vapi's built-in voices, or ElevenLabs' voice library (searchable, by language) and the account's own voices. */
    public function voices(Request $request, VoiceAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['vapi', 'elevenlabs'])], 'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'source' => ['nullable', Rule::in(['library', 'mine'])], 'search' => ['nullable', 'string', 'max:100'], 'language' => ['nullable', 'regex:/^[a-z]{2,3}$/'],
            'gender' => ['nullable', Rule::in(['male', 'female'])], 'page' => ['nullable', 'integer', 'min:0', 'max:300'],
        ]);
        if ($data['provider'] === 'vapi') {
            return response()->json(['has_more' => false, 'voices' => collect(['Elliot' => 'Male, calm', 'Kylie' => 'Female, friendly', 'Rohan' => 'Male, Indian English', 'Lily' => 'Female, bright', 'Savannah' => 'Female, Southern US',
                'Hana' => 'Female, soft', 'Neha' => 'Female, Indian English', 'Cole' => 'Male, deep', 'Harry' => 'Male, British', 'Paige' => 'Female, warm', 'Spencer' => 'Male, energetic'])
                ->map(fn ($d, $name) => ['id' => $name, 'name' => $name, 'description' => $d, 'preview' => null])->values()]);
        }
        $http = $this->elevenLabs($data['credential_id'] ?? null);
        $search = trim((string) ($data['search'] ?? ''));
        $label = fn (array $parts) => collect($parts)->filter()->map(fn ($p) => str_replace('_', ' ', (string) $p))->unique()->implode(', ');

        if (($data['source'] ?? 'library') === 'mine') {
            $response = $http->get('https://api.elevenlabs.io/v1/voices');
            if (! $response->successful()) {
                return response()->json(['message' => "ElevenLabs didn't list your voices (HTTP {$response->status()}). Check the API key."], 422);
            }

            return response()->json(['has_more' => false, 'voices' => collect($response->json('voices', []))
                ->map(fn ($v) => ['id' => $v['voice_id'], 'name' => $v['name'], 'preview' => $v['preview_url'] ?? null, 'added' => true,
                    // Voices copied from the library only work through the API on paid plans; picking one tests it first.
                    'library' => ($v['sharing']['status'] ?? null) === 'copied' || in_array($v['category'] ?? '', ['professional', 'famous', 'high_quality'], true), 'default' => ($v['category'] ?? '') === 'premade',
                    'description' => $label(Arr::only($v['labels'] ?? [], ['gender', 'accent', 'age', 'description', 'use_case']))])
                ->filter(fn ($v) => $search === '' || Str::contains($v['name'].' '.$v['description'], $search, true))
                ->sortBy(fn ($v) => [$v['library'] ? 2 : ($v['default'] ? 1 : 0), $v['name']])->values()]);
        }

        $language = $data['language'] ?? null;
        $response = $http->get('https://api.elevenlabs.io/v1/shared-voices', array_filter([
            'page_size' => 30, 'page' => $data['page'] ?? 0, 'search' => $search, 'language' => $language, 'gender' => $data['gender'] ?? null, 'sort' => $search === '' ? 'trending' : null,
        ], fn ($v) => $v !== null && $v !== ''));
        if (! $response->successful()) {
            return response()->json(['message' => "ElevenLabs didn't list its voice library (HTTP {$response->status()}). Check the API key."], 422);
        }

        return response()->json(['has_more' => (bool) $response->json('has_more'), 'voices' => collect($response->json('voices', []))->map(fn ($v) => [
            'id' => $v['voice_id'], 'owner' => $v['public_owner_id'] ?? null, 'name' => trim((string) $v['name']), 'added' => (bool) ($v['is_added_by_user'] ?? false),
            'description' => $label([$v['gender'] ?? null, $v['age'] ?? null, $v['accent'] ?? null, $v['descriptive'] ?? null, $v['use_case'] ?? null]),
            'language' => $v['language'] ?? null, 'paid' => ! ($v['free_users_allowed'] ?? true),
            // A sample in the chosen language when ElevenLabs has one.
            'preview' => ($language ? collect($v['verified_languages'] ?? [])->where('language', $language)->pluck('preview_url')->filter()->first() : null) ?? ($v['preview_url'] ?? null),
        ])->values()]);
    }

    /**
     * Readies a voice-library voice for the agent: a three-character test first (the free ElevenLabs plan can't use
     * library voices through the API, so nothing is added when it fails), then adds it to the account if needed.
     */
    public function addVoice(Request $request, VoiceAgent $agent): JsonResponse
    {
        $data = $request->validate(['credential_id' => ['required', 'integer', 'exists:credentials,id'], 'owner' => ['nullable', 'string', 'max:100'],
            'voice_id' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:120'], 'added' => ['boolean']]);
        $http = $this->elevenLabs($data['credential_id']);
        $probe = $http->accept('audio/mpeg')->post('https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($data['voice_id']).'?output_format=mp3_22050_32', ['text' => 'Hi.', 'model_id' => 'eleven_flash_v2_5']);
        if (! $probe->successful()) {
            return response()->json(['message' => $this->elevenLabsError($probe, "can't use this voice")], 422);
        }
        if (! empty($data['owner']) && ! ($data['added'] ?? false)) {
            $response = $http->acceptJson()->post('https://api.elevenlabs.io/v1/voices/add/'.rawurlencode($data['owner']).'/'.rawurlencode($data['voice_id']), ['new_name' => Str::limit($data['name'], 100, '')]);
            if (! $response->successful() && ! Str::contains($this->elevenLabsError($response, ''), 'already', true)) {
                return response()->json(['message' => $this->elevenLabsError($response, "couldn't add this voice").' Free plans have a limited number of voice slots; remove one at elevenlabs.io or pick another voice.'], 422);
            }

            return response()->json(['voice_id' => $response->json('voice_id') ?: $data['voice_id']]);
        }

        return response()->json(['voice_id' => $data['voice_id']]);
    }

    private function elevenLabsError($response, string $what): string
    {
        $detail = $response->json('detail');
        $message = (is_array($detail) ? ($detail['message'] ?? null) : $detail) ?: 'HTTP '.$response->status();
        if (Str::contains($message, 'library voices', true)) {
            $message .= ' On the free plan, pick one of the default voices under Choose a voice, My voices.';
        }

        return $what === '' ? $message : "ElevenLabs {$what}: {$message}";
    }

    /** Speaks a test-call reply with the agent's ElevenLabs voice (MP3), so test calls sound like real ones. */
    public function speak(Request $request, VoiceAgent $agent): HttpResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:1500']])['text'];
        if ($agent->provider !== 'elevenlabs' || ! $agent->credential_id) {
            return response()->json(['message' => 'Only agents on ElevenLabs speak with a cloud voice.'], 422);
        }
        $response = $this->elevenLabs($agent->credential_id)->accept('audio/mpeg')->timeout(30)
            ->post('https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($agent->setting('voice') ?: ElevenLabsProvider::DEFAULT_VOICE).'?output_format=mp3_44100_64',
                ['text' => $text, 'model_id' => 'eleven_flash_v2_5']);
        if (! $response->successful()) {
            return response()->json(['message' => $this->elevenLabsError($response, "couldn't speak")], 422);
        }

        return response($response->body(), 200, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'no-store']);
    }

    private function elevenLabs(?int $credentialId): PendingRequest
    {
        $key = Credential::query()->find($credentialId ?? 0)?->data['api_key'] ?? null;
        abort_unless($key, 422, 'Choose your ElevenLabs account first.');

        return Http::withHeaders(['xi-api-key' => $key])->acceptJson()->timeout(20);
    }

    /** The provider's phone numbers, for the Phone tab (works before saving: provider and credential come from the form). */
    public function numbers(Request $request, VoiceAgent $agent): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', Rule::in(['vapi', 'elevenlabs'])], 'credential_id' => ['required', 'integer', 'exists:credentials,id']]);
        $probe = $agent->replicate()->fill(['provider' => $data['provider'], 'credential_id' => $data['credential_id']]);
        $probe->setRelation('credential', Credential::query()->find($data['credential_id']));
        try {
            return response()->json(['numbers' => $this->voice->provider($probe)->phoneNumbers($probe)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function call(Request $request, VoiceAgent $agent): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:40'], 'variables' => ['nullable', 'array']]);
        try {
            $call = $this->voice->call($agent, $data['to'], (array) ($data['variables'] ?? []));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['call' => $this->callRow($call)]);
    }

    /** One turn of the free browser conversation. */
    public function talk(Request $request, VoiceAgent $agent): JsonResponse
    {
        $data = $request->validate(['call_id' => ['nullable', 'integer'], 'text' => ['nullable', 'string', 'max:2000'], 'variables' => ['nullable', 'array']]);
        $call = ! empty($data['call_id']) ? $agent->calls()->where('direction', 'web')->whereKey($data['call_id'])->firstOrFail() : null;
        if ($call?->isDone()) {
            return response()->json(['message' => 'This conversation has ended. Start a new one.'], 422);
        }
        try {
            $turn = $this->voice->talk($agent, $call, (string) ($data['text'] ?? ''), (array) ($data['variables'] ?? []));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['call_id' => $turn['call']->id, 'reply' => $turn['reply'], 'ended' => $turn['ended'] ?? false,
            'booking' => $turn['booking'] ? $this->voice->bookingPayload($turn['booking']) : null]);
    }

    public function endTalk(VoiceAgent $agent, VoiceCall $call): JsonResponse
    {
        abort_unless($call->voice_agent_id === $agent->id && $call->direction === 'web', 404);

        return response()->json(['call' => $this->callDetail($this->voice->endTalk($call))]);
    }

    public function calls(Request $request): Response
    {
        $filters = $request->validate(['agent' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:20'], 'open' => ['nullable', 'integer']]);
        $calls = VoiceCall::query()->with('agent:id,name')->withCount('bookings')
            ->when($filters['agent'] ?? null, fn ($q, $id) => $q->where('voice_agent_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(25)->withQueryString();

        return Inertia::render('Voice/Calls', [
            'calls' => $calls->through(fn (VoiceCall $c) => $this->callRow($c)),
            'agents' => VoiceAgent::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function callShow(VoiceCall $call): JsonResponse
    {
        return response()->json(['call' => $this->callDetail($call)]);
    }

    /** Plays a recording: a direct link (Vapi) or streamed through FlowForge with the account key (ElevenLabs). */
    public function recording(VoiceCall $call): HttpResponse
    {
        $recording = $call->recording_url ? rescue(fn () => $this->voice->provider($call->agent)->recording($call), null, false) : null;
        abort_unless($recording, 404);

        return isset($recording['url']) ? redirect()->away($recording['url'])
            : response($recording['body'], 200, ['Content-Type' => $recording['type'] ?? 'audio/mpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function bookings(Request $request): Response
    {
        $show = $request->validate(['show' => ['nullable', Rule::in(['upcoming', 'past', 'cancelled', 'all'])]])['show'] ?? 'upcoming';
        $timezone = $this->settings->timezone();
        $bookings = VoiceBooking::query()->with('agent:id,name')
            ->when($show === 'upcoming', fn ($q) => $q->where('status', 'booked')->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->subHours(2)))->orderByRaw('starts_at IS NULL, starts_at'))
            ->when($show === 'past', fn ($q) => $q->where('status', '!=', 'cancelled')->where('starts_at', '<', now()->subHours(2))->latest('starts_at'))
            ->when($show === 'cancelled', fn ($q) => $q->where('status', 'cancelled')->latest())
            ->when($show === 'all', fn ($q) => $q->latest())
            ->paginate(30)->withQueryString();

        return Inertia::render('Voice/Bookings', [
            'bookings' => $bookings->through(fn (VoiceBooking $b) => [
                'id' => $b->id, 'name' => $b->name, 'phone' => $b->phone, 'email' => $b->email, 'service' => $b->service, 'notes' => $b->notes, 'status' => $b->status,
                'starts_at' => $b->starts_at?->toIso8601String(), 'requested_time' => $b->requested_time, 'agent' => $b->agent?->name, 'call_id' => $b->voice_call_id,
                'created_at' => $b->created_at->toIso8601String(),
            ]),
            'show' => $show,
            'timezone' => $timezone,
        ]);
    }

    public function updateBooking(Request $request, VoiceBooking $booking): RedirectResponse
    {
        $booking->update($request->validate(['status' => ['required', Rule::in(['booked', 'done', 'cancelled'])]]));

        return back()->with('success', 'Booking updated.');
    }

    /** Agents for the "Voice agent" field in workflow steps. */
    public function options(): JsonResponse
    {
        return response()->json(['agents' => VoiceAgent::query()->orderBy('name')->get(['id', 'name', 'provider'])]);
    }

    private function callRow(VoiceCall $c): array
    {
        return [
            'id' => $c->id, 'agent' => $c->agent?->name, 'agent_id' => $c->voice_agent_id, 'direction' => $c->direction, 'status' => $c->status,
            'from' => $c->from_number, 'to' => $c->to_number, 'started_at' => ($c->started_at ?? $c->created_at)->toIso8601String(),
            'duration' => $c->duration_seconds, 'summary' => $c->summary, 'outcome' => $c->outcome, 'has_recording' => (bool) $c->recording_url,
            'bookings' => $c->bookings_count ?? null,
        ];
    }

    private function callDetail(VoiceCall $c): array
    {
        return $this->callRow($c) + [
            'transcript' => $c->transcript ?? [], 'data' => $c->data ?? [], 'cost' => $c->cost, 'ended_reason' => $c->ended_reason,
            'recording' => $c->recording_url ? url("/voice/calls/{$c->id}/recording") : null,
            'booking_list' => $c->bookings()->get(['id', 'name', 'starts_at', 'requested_time', 'service', 'status'])->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'starts_at' => $b->starts_at?->toIso8601String(), 'requested_time' => $b->requested_time, 'service' => $b->service, 'status' => $b->status,
            ]),
        ];
    }
}
