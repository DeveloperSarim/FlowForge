<?php

namespace Tests\Feature;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\Providers;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Integrations\Models\Credential;
use App\Domain\System\SystemSettings;
use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceBooking;
use App\Domain\Voice\Models\VoiceCall;
use App\Domain\Voice\VoiceService;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        app(SystemSettings::class)->set('timezone', 'Asia/Karachi');
    }

    private function agent(string $provider = 'browser', array $attrs = []): VoiceAgent
    {
        $agent = app(VoiceService::class)->create('Front desk', 'clinic-receptionist', ['business_name' => 'Smile Dental']);
        $agent->forceFill(['provider' => $provider] + $attrs)->save();

        return $agent;
    }

    private function listen(string $trigger): Workflow
    {
        return Workflow::query()->create(['name' => "On $trigger", 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'voice', 'trigger' => $trigger, 'config' => []],
        ]], 'edges' => []]]);
    }

    public function test_templates_create_agents_and_the_pages_open(): void
    {
        $this->get('/voice')->assertOk()->assertInertia(fn ($page) => $page->component('Voice/Agents')->has('templates', 8));

        $this->post('/voice/agents', ['template' => 'clinic-receptionist'])->assertRedirect();
        $agent = VoiceAgent::query()->firstOrFail();
        $this->assertTrue($agent->bookingEnabled());
        $this->assertStringContainsString('{{business_name}}', $agent->setting('instructions'));

        $this->get("/voice/agents/{$agent->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Voice/Agent')->where('agent.provider', 'browser'));
        $this->get('/voice/calls')->assertOk();
        $this->get('/voice/bookings')->assertOk();
    }

    public function test_saving_a_vapi_agent_publishes_it_with_the_booking_tool(): void
    {
        $credential = Credential::query()->create(['name' => 'Vapi', 'integration' => 'vapi', 'auth_type' => 'bearer', 'data' => ['token' => 'vapi-key']]);
        $agent = $this->agent();
        Http::fake(['api.vapi.ai/assistant' => Http::response(['id' => 'asst_1']), 'api.vapi.ai/phone-number/*' => Http::response(['id' => 'pn_1'])]);

        $this->put("/voice/agents/{$agent->id}", ['name' => 'Front desk', 'provider' => 'vapi', 'credential_id' => $credential->id, 'phone_number_id' => 'pn_1',
            'phone_label' => '+14155550100', 'config' => $agent->config])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('asst_1', $agent->fresh()->remote_id);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.vapi.ai/assistant' && $r->hasHeader('Authorization', 'Bearer vapi-key')
            && $r['model']['tools'][0]['function']['name'] === 'book_appointment'
            && $r['server']['url'] === $agent->eventsUrl()
            && str_contains($r['model']['messages'][0]['content'], 'Smile Dental') && ! str_contains($r['model']['messages'][0]['content'], '{{business_name}}'));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://api.vapi.ai/phone-number/pn_1' && $r['assistantId'] === 'asst_1');
    }

    public function test_a_booking_during_a_vapi_call_is_saved_and_starts_workflows(): void
    {
        $agent = $this->agent('vapi', ['remote_id' => 'asst_1']);
        $this->listen('booking_created');

        $this->postJson("/api/voice/{$agent->token}", ['message' => ['type' => 'tool-calls',
            'call' => ['id' => 'call_9', 'type' => 'inboundPhoneCall', 'status' => 'in-progress', 'customer' => ['number' => '+923001234567']],
            'toolCallList' => [['id' => 'tc_1', 'type' => 'function', 'function' => ['name' => 'book_appointment',
                'arguments' => ['name' => 'Sara Khan', 'date_time' => now('Asia/Karachi')->addDays(2)->setTime(15, 0)->format('Y-m-d H:i'), 'service' => 'Check-up']]]],
        ]])->assertOk()->assertJsonPath('results.0.toolCallId', 'tc_1')->assertJson(fn ($json) => $json->where('results.0.result', fn ($r) => str_starts_with($r, 'Booked for Sara Khan on ')));

        $booking = VoiceBooking::query()->firstOrFail();
        $this->assertSame('+923001234567', $booking->phone); // taken from the call when the agent didn't ask
        $this->assertSame('15:00', $booking->starts_at->setTimezone('Asia/Karachi')->format('H:i'));
        $this->assertSame('call_9', $booking->call->remote_id);
        $this->assertSame(1, WorkflowExecution::query()->where('mode', 'voice')->count());

        // A time in the past is sent back to the agent to fix with the caller.
        $this->postJson("/api/voice/{$agent->token}", ['message' => ['type' => 'tool-calls', 'call' => ['id' => 'call_9'],
            'toolCallList' => [['id' => 'tc_2', 'function' => ['name' => 'book_appointment', 'arguments' => '{"name":"Ali","date_time":"2020-01-01 10:00"}']]]]])
            ->assertJsonPath('results.0.result', 'That time has already passed. Ask the caller for a future date and time.');
        $this->assertSame(1, VoiceBooking::query()->count());
    }

    public function test_an_end_of_call_report_saves_the_call_once_and_starts_workflows_once(): void
    {
        $agent = $this->agent('vapi', ['remote_id' => 'asst_1', 'phone_label' => '+14155550100']);
        $this->listen('call_ended');
        $report = ['message' => ['type' => 'end-of-call-report', 'endedReason' => 'customer-ended-call',
            'call' => ['id' => 'call_7', 'type' => 'inboundPhoneCall', 'customer' => ['number' => '+923001234567'], 'startedAt' => '2026-10-14T10:00:00Z', 'endedAt' => '2026-10-14T10:01:34Z', 'cost' => 0.12],
            'artifact' => ['recordingUrl' => 'https://storage.vapi.ai/call_7.wav', 'messages' => [
                ['role' => 'system', 'message' => 'You are…'], ['role' => 'bot', 'message' => 'Hello, Smile Dental.', 'secondsFromStart' => 0.5], ['role' => 'user', 'message' => 'I need a check-up.', 'secondsFromStart' => 3.1],
            ]],
            'analysis' => ['summary' => 'Caller wants a check-up.', 'structuredData' => ['reason' => 'Check-up'], 'successEvaluation' => 'true'],
        ]];

        $this->postJson("/api/voice/{$agent->token}", $report)->assertOk();
        $this->postJson("/api/voice/{$agent->token}", $report)->assertOk();

        $call = VoiceCall::query()->sole();
        $this->assertSame(['completed', 'inbound', '+923001234567', '+14155550100', 94, 'success'], [$call->status, $call->direction, $call->from_number, $call->to_number, $call->duration_seconds, $call->outcome]);
        $this->assertSame([['role' => 'agent', 'text' => 'Hello, Smile Dental.', 'at' => 0.5], ['role' => 'user', 'text' => 'I need a check-up.', 'at' => 3.1]], $call->transcript);
        $this->assertSame(['reason' => 'Check-up'], $call->data);
        $this->assertSame(1, WorkflowExecution::query()->where('mode', 'voice')->count());
        $this->getJson("/voice/calls/{$call->id}")->assertOk()->assertJsonPath('call.summary', 'Caller wants a check-up.');
        $this->get("/voice/calls/{$call->id}/recording")->assertRedirect('https://storage.vapi.ai/call_7.wav');
    }

    public function test_elevenlabs_agents_place_calls_and_the_poll_imports_finished_ones(): void
    {
        $credential = Credential::query()->create(['name' => 'ElevenLabs', 'integration' => 'elevenlabs', 'auth_type' => 'api_key', 'data' => ['api_key' => 'xi-key']]);
        $agent = $this->agent('elevenlabs', ['credential_id' => $credential->id, 'remote_id' => 'agent_1', 'phone_number_id' => 'phnum_1', 'phone_label' => '+14155550100']);
        Http::fake([
            'api.elevenlabs.io/v1/convai/phone-numbers' => Http::response([['phone_number_id' => 'phnum_1', 'phone_number' => '+14155550100', 'provider' => 'twilio', 'label' => 'Main']]),
            'api.elevenlabs.io/v1/convai/twilio/outbound-call' => Http::response(['success' => true, 'message' => 'ok', 'conversation_id' => 'conv_1', 'callSid' => 'CA1']),
            'api.elevenlabs.io/v1/convai/conversations?*' => Http::response(['conversations' => [['conversation_id' => 'conv_1', 'agent_id' => 'agent_1', 'status' => 'done', 'start_time_unix_secs' => 1_792_000_000, 'call_duration_secs' => 40, 'call_successful' => 'success']], 'has_more' => false]),
            'api.elevenlabs.io/v1/convai/conversations/conv_1' => Http::response(['conversation_id' => 'conv_1', 'agent_id' => 'agent_1', 'status' => 'done', 'has_audio' => true,
                'transcript' => [['role' => 'agent', 'message' => 'Hi Sara!', 'time_in_call_secs' => 0], ['role' => 'user', 'message' => 'Hi.', 'time_in_call_secs' => 2]],
                'metadata' => ['start_time_unix_secs' => 1_792_000_000, 'call_duration_secs' => 40, 'phone_call' => ['direction' => 'outbound', 'external_number' => '+923001234567', 'agent_number' => '+14155550100']],
                'analysis' => ['transcript_summary' => 'Reminder confirmed.', 'call_successful' => 'success', 'data_collection_results' => ['answer' => ['value' => 'confirmed']]]]),
        ]);

        $this->postJson("/voice/agents/{$agent->id}/call", ['to' => '0300 123'])->assertStatus(422);
        $this->postJson("/voice/agents/{$agent->id}/call", ['to' => '+92 300 1234567', 'variables' => ['customer_name' => 'Sara']])->assertOk()->assertJsonPath('call.status', 'queued');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/twilio/outbound-call') && $r->hasHeader('xi-api-key', 'xi-key') && $r['to_number'] === '+923001234567'
            && $r['conversation_initiation_client_data']['dynamic_variables'] === ['customer_name' => 'Sara']);

        app(VoiceService::class)->poll();

        $call = VoiceCall::query()->sole();
        $this->assertSame(['completed', 'outbound', 40, 'Reminder confirmed.', 'success'], [$call->status, $call->direction, $call->duration_seconds, $call->summary, $call->outcome]);
        $this->assertSame('confirmed', $call->data['answer']);
        $this->assertSame('Hi Sara!', $call->transcript[0]['text']);
    }

    public function test_the_free_browser_conversation_books_and_summarises(): void
    {
        app(SystemSettings::class)->set('ai', ['provider' => 'deepseek', 'api_key' => 'k', 'model' => 'deepseek-chat', 'base_url' => Providers::get('deepseek')['base_url']]);
        $agent = $this->agent();
        $this->listen('call_ended');
        $when = now('Asia/Karachi')->addDays(3)->setTime(11, 0)->format('Y-m-d H:i');
        Http::fakeSequence('api.deepseek.com/*')
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'b1', 'type' => 'function', 'function' => ['name' => 'book_appointment', 'arguments' => json_encode(['name' => 'Sara Khan', 'date_time' => $when])]]]]]], 'usage' => []])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'You are booked, Sara.']]], 'usage' => []])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"summary":"Sara booked a check-up.","outcome":"success","data":{"reason":"Check-up","urgent":null}}']]], 'usage' => []]);

        $greeting = $this->postJson("/voice/agents/{$agent->id}/talk", ['text' => ''])->assertOk();
        $this->assertSame('Hello, thanks for calling Smile Dental. This is the virtual receptionist. How can I help you today?', $greeting->json('reply'));
        $id = $greeting->json('call_id');

        $this->postJson("/voice/agents/{$agent->id}/talk", ['call_id' => $id, 'text' => 'Book me in please, Sara Khan.'])->assertOk()
            ->assertJsonPath('reply', 'You are booked, Sara.')->assertJsonPath('booking.name', 'Sara Khan');
        $this->postJson("/voice/agents/{$agent->id}/talk/{$id}/end")->assertOk()->assertJsonPath('call.summary', 'Sara booked a check-up.');

        $call = VoiceCall::query()->sole();
        $this->assertSame(['completed', 'web', 'success', ['reason' => 'Check-up']], [$call->status, $call->direction, $call->outcome, $call->data]);
        $this->assertCount(3, $call->transcript);
        $this->assertSame(1, VoiceBooking::query()->count());
        $this->assertSame(1, WorkflowExecution::query()->where('mode', 'voice')->count());
    }

    public function test_the_ai_builder_can_create_a_voice_agent(): void
    {
        $tools = app(WorkflowTools::class);
        [$out, , $error] = $tools->call('create_voice_agent', ['name' => 'Pizza line', 'template' => 'restaurant-reservations', 'business_name' => 'Pizza Point'], AiConversation::query()->create(['user_id' => auth()->id(), 'provider' => 'deepseek', 'model' => 'deepseek-chat']));

        $this->assertFalse($error);
        $agent = VoiceAgent::query()->findOrFail($out['id']);
        $this->assertSame(['Pizza line', 'Pizza Point', true], [$agent->name, $agent->setting('business_name'), $agent->bookingEnabled()]);
        $this->assertSame($agent->id, $tools->call('list_voice_agents', [], AiConversation::query()->firstOrFail())[0]['agents'][0]['id']);
    }
}
