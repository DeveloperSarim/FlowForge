<?php

namespace Tests\Feature;

use App\Domain\Assistant\LlmClient;
use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\Providers;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Integrations\Models\Credential;
use App\Domain\System\SystemSettings;
use App\Domain\System\WorkflowFailedMail;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private array $graph = [
        'name' => 'Leads to Slack',
        'nodes' => [
            ['id' => 'trigger', 'app' => 'webhook', 'op' => 'catch', 'config' => ['method' => 'POST']],
            ['id' => 'step_1', 'app' => 'slack', 'op' => 'post_message', 'config' => ['channel' => '#sales', 'text' => 'New lead: {{ steps.trigger.data.email }}']],
        ],
        'edges' => [['from' => 'trigger', 'to' => 'step_1']],
    ];

    private function configure(string $provider, string $model): void
    {
        app(SystemSettings::class)->set('ai', ['provider' => $provider, 'api_key' => 'test-key', 'model' => $model, 'base_url' => Providers::get($provider)['base_url'], 'effort' => 'medium']);
    }

    private function chat(string $message): array
    {
        $conversation = $this->postJson('/assistant/conversations')->assertOk()->json('conversation');

        return $this->postJson("/assistant/conversations/{$conversation['id']}/messages", ['message' => $message])->assertOk()->json('conversation');
    }

    public function test_openai_compatible_agent_searches_apps_and_builds_a_workflow(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('deepseek', 'deepseek-chat');
        Credential::query()->create(['name' => 'Slack bot', 'integration' => 'slack', 'auth_type' => 'bearer', 'data' => ['token' => 'xoxb-1']]);

        Http::fakeSequence('api.deepseek.com/*')
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null, 'reasoning_content' => 'hidden',
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_apps', 'arguments' => '{"query":"slack"}']]]]]],
                'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 30, 'prompt_cache_hit_tokens' => 1000]])
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'save_workflow', 'arguments' => json_encode($this->graph)]]]]]],
                'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 120]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Built /workflows/1.']]],
                'usage' => ['prompt_tokens' => 1600, 'completion_tokens' => 20]]);

        $conversation = $this->chat('Send new webhook leads to #sales on Slack');

        $workflow = Workflow::query()->sole();
        $this->assertSame('webhook', $workflow->trigger_type);
        $this->assertSame(Credential::query()->sole()->id, $workflow->graph['nodes'][1]['data']['credential_id'], 'single matching credential is attached');
        $this->assertSame('created', $conversation['messages'][1]['actions'][0]['type']);
        $this->assertSame(['input' => 4300, 'cached' => 1000, 'output' => 170, 'calls' => 3], $conversation['messages'][1]['usage']);

        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? [];
            $assistant = collect($messages)->firstWhere('role', 'assistant');

            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $messages[0]['role'] === 'system'
                && collect($request['tools'])->pluck('function.name')->contains('save_workflow')
                && (! $assistant || ! array_key_exists('reasoning_content', $assistant)); // never echoed back
        });
    }

    public function test_chat_streams_live_progress_steps_and_keeps_them_on_the_message(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('deepseek', 'deepseek-chat');
        Http::fakeSequence('api.deepseek.com/*')
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => 'Let me find Slack.',
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_apps', 'arguments' => '{"query":"slack"}']]]]]], 'usage' => []])
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'save_workflow', 'arguments' => json_encode($this->graph)]]]]]], 'usage' => []])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Built /workflows/1.']]], 'usage' => []]);
        $id = $this->postJson('/assistant/conversations')->json('conversation.id');

        $response = $this->post("/assistant/conversations/$id/messages", ['message' => 'Leads to Slack'], ['Accept' => 'application/x-ndjson']);
        $events = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $response->streamedContent())));

        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $steps = array_values(array_filter($events, fn ($e) => $e['type'] === 'step'));
        $this->assertSame(['Searching apps for "slack"', 'Searching apps for "slack"', 'Building "Leads to Slack"', 'Building "Leads to Slack"'], array_column($steps, 'label'));
        $this->assertSame(['running', 'done', 'running', 'done'], array_column($steps, 'state'));
        $this->assertContains(['type' => 'text', 'text' => 'Let me find Slack.'], $events);
        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertCount(2, $done['conversation']['messages'][1]['steps']);
    }

    public function test_claude_agent_uses_the_sdk_with_caching_effort_and_fallbacks(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('anthropic', 'claude-opus-5-5');

        $usage = ['input_tokens' => 50, 'output_tokens' => 40, 'cache_read_input_tokens' => 2000, 'cache_creation_input_tokens' => 0];
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'm1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'stop_sequence' => null, 'usage' => $usage,
                'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'SIG'], ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'save_workflow', 'input' => $this->graph]]])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'm2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => $usage,
                'content' => [['type' => 'text', 'text' => 'Done.']]])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = app(LlmClient::class);
        $client->anthropicTransport = new Client(['handler' => $stack]);
        $this->app->instance(LlmClient::class, $client);

        $conversation = $this->chat('Send webhook leads to Slack');

        $this->assertSame(1, Workflow::query()->count());
        $this->assertSame('Done.', $conversation['messages'][1]['text']);

        $first = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['type' => 'ephemeral'], $first['cache_control']);
        $this->assertSame('default', $first['fallbacks']);
        $this->assertSame('medium', $first['output_config']['effort']);
        $this->assertArrayHasKey('input_schema', $first['tools'][0]);
        $this->assertSame('server-side-fallback-2026-07-01', $history[0]['request']->getHeaderLine('anthropic-beta'));

        $second = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('SIG', $second['messages'][1]['content'][0]['signature'], 'thinking block echoed back unchanged');
        $this->assertSame('tu1', $second['messages'][2]['content'][0]['tool_use_id']);
    }

    public function test_gemini_agent_round_trips_function_calls(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('gemini', 'gemini-2.5-flash');

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'list_workflows', 'args' => new \stdClass], 'thoughtSignature' => 'TS1'],
            ]]]], 'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 10]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'You have no workflows yet.']]]]],
                'usageMetadata' => ['promptTokenCount' => 950, 'candidatesTokenCount' => 12, 'cachedContentTokenCount' => 800]]);

        $conversation = $this->chat('kitne workflows hain?');

        $this->assertSame('You have no workflows yet.', $conversation['messages'][1]['text']);
        Http::assertSent(function ($request) {
            $contents = $request['contents'] ?? [];

            return count($contents) === 3
                && ($contents[1]['parts'][0]['thoughtSignature'] ?? null) === 'TS1'
                && isset($contents[2]['parts'][0]['functionResponse'])
                && ! isset(collect($request['tools'][0]['functionDeclarations'])->firstWhere('name', 'list_workflows')['parameters']);
        });
    }

    public function test_deletes_need_user_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configure('openai', 'gpt-5-mini');
        $workflow = Workflow::query()->create(['name' => 'Old', 'graph' => Workflow::defaultGraph()]);

        Http::fakeSequence('api.openai.com/*')
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'delete_workflow', 'arguments' => '{"id":'.$workflow->id.'}']]]]]], 'usage' => []])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Confirm on the card.']]], 'usage' => []]);

        $conversation = $this->chat('delete Old');
        $this->assertSame('delete_pending', $conversation['messages'][1]['actions'][0]['type']);
        $this->assertNotNull($workflow->fresh(), 'nothing deleted before confirmation');

        $after = $this->postJson("/assistant/conversations/{$conversation['id']}/confirm-delete", ['workflow_id' => $workflow->id])->assertOk()->json('conversation');
        $this->assertNull($workflow->fresh());
        $this->assertSame('deleted', $after['messages'][1]['actions'][0]['type']);
        Http::assertSent(fn ($r) => isset($r['max_completion_tokens']) && $r['reasoning_effort'] === 'medium');
    }

    public function test_invalid_graph_from_the_model_is_reported_back_not_saved(): void
    {
        $tools = app(WorkflowTools::class);
        [$output, $action, $isError] = $tools->call('save_workflow', [
            'name' => 'Broken',
            'nodes' => [['id' => 'trigger', 'app' => 'webhook', 'op' => 'catch'], ['id' => 'step_1', 'app' => 'slack', 'op' => 'send_dm', 'config' => []]],
            'edges' => [['from' => 'trigger', 'to' => 'step_1']],
        ], new AiConversation);

        $this->assertTrue($isError);
        $this->assertStringContainsString('slack has no action [send_dm]. Valid: post_message', $output['error']);
        $this->assertSame(0, Workflow::query()->count());
    }

    public function test_the_ai_never_sees_secrets_typed_into_steps_and_saving_back_keeps_them(): void
    {
        $tools = app(WorkflowTools::class);
        $nodes = fn (string $secret) => [['id' => 'trigger', 'app' => 'webhook', 'op' => 'catch', 'config' => ['verify' => 'header', 'secret_header' => 'X-Key', 'signing_secret' => $secret]]];
        $saved = $tools->call('save_workflow', ['name' => 'Hook', 'nodes' => $nodes('s3cret-value'), 'edges' => []], new AiConversation)[0];

        $seen = $tools->call('get_workflow', ['id' => $saved['id']], new AiConversation)[0];
        $this->assertStringNotContainsString('s3cret-value', json_encode($seen));
        $this->assertSame(WorkflowTools::HIDDEN, $seen['nodes'][0]['config']['signing_secret']);

        $tools->call('save_workflow', ['id' => $saved['id'], 'name' => 'Hook v2', 'nodes' => $nodes(WorkflowTools::HIDDEN), 'edges' => []], new AiConversation);
        $this->assertSame('s3cret-value', Workflow::query()->find($saved['id'])->graph['nodes'][0]['data']['config']['signing_secret']);
    }

    public function test_step_references_are_checked_against_what_each_step_returns(): void
    {
        $tools = app(WorkflowTools::class);
        $save = fn (string $text) => $tools->call('save_workflow', [
            'name' => 'LinkedIn poster',
            'nodes' => [
                ['id' => 'trigger', 'app' => 'schedule', 'op' => 'cron', 'config' => ['cron' => '0 11 * * *']],
                ['id' => 'step_1', 'app' => 'deepseek', 'op' => 'chat_completion', 'config' => ['prompt' => 'Write a post']],
                ['id' => 'step_2', 'app' => 'transform', 'op' => 'text_format', 'config' => ['text' => $text]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'step_1'], ['from' => 'step_1', 'to' => 'step_2']],
        ], new AiConversation);

        // The model's real mistake: DeepSeek's reply is "text", not "content". Saved, but flagged for the model to fix.
        [$output] = $save('{{ steps.step_1.content }}');
        $this->assertStringContainsString('Fix: Node [step_2] {{ steps.step_1.content }} — "content" is not a known output of deepseek.chat_completion. Documented fields: text', implode("\n", $output['warnings']));

        [$output] = $save('{{ steps.step_1.text }}');
        $this->assertStringNotContainsString('Fix:', implode("\n", $output['warnings'] ?? []));

        // Closed outputs (triggers, built-ins) and missing/downstream steps are hard errors.
        [$output, , $isError] = $save('{{ steps.trigger.email }}');
        $this->assertTrue($isError);
        $this->assertStringContainsString('payload fields are under data', $output['error']);
        [$output, , $isError] = $save('{{ steps.step_9.text }}');
        $this->assertTrue($isError);
        $this->assertStringContainsString('there is no step "step_9"', $output['error']);

        $app = $tools->call('get_app', ['app' => 'deepseek', 'ops' => ['chat_completion']], new AiConversation)[0];
        $this->assertSame('text +raw API response', $app['actions']['chat_completion']['out']);
    }

    public function test_failed_production_run_emails_admin_once_per_window_and_starts_error_workflows(): void
    {
        Mail::fake();
        Http::fake(['jsonplaceholder.typicode.com/*' => Http::response(['error' => 'down'], 500)]);
        User::factory()->create(['email' => 'admin@example.com']);
        app(SystemSettings::class)->set('mail', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'ff@example.com', 'from_name' => 'FlowForge']);

        $this->seed();
        $workflow = Workflow::query()->sole();
        // With automatic retries on, the email waits for the last try (see RetriesTest); here every failure is final.
        $workflow->update(['is_active' => true, 'settings' => ['auto_retry' => false]]);
        $alerts = Workflow::query()->create(['name' => 'Alerts', 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'error_trigger', 'trigger' => 'workflow_failed', 'config' => []],
        ]], 'edges' => []]]);

        $this->postJson("/api/webhook/{$workflow->webhook_token}", ['plan' => 'pro', 'email' => 'a@b.c']);
        $this->postJson("/api/webhook/{$workflow->webhook_token}", ['plan' => 'pro', 'email' => 'a@b.c']);

        $this->assertSame(2, $workflow->executions()->where('status', 'failed')->count());
        Mail::assertSent(WorkflowFailedMail::class, 1);
        Mail::assertSent(WorkflowFailedMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        $this->assertSame(2, $alerts->executions()->count(), 'error-trigger workflow started for each failure');
    }

    public function test_test_runs_never_send_failure_emails(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        app(SystemSettings::class)->set('mail', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'ff@example.com']);
        $this->seed();
        $workflow = Workflow::query()->sole();
        $graph = $workflow->graph;
        $graph['nodes'][0]['data']['config']['sample_payload'] = '{"plan":"pro"}';
        $workflow->update(['graph' => $graph]);

        $this->actingAs(User::factory()->create())->postJson("/workflows/{$workflow->id}/test")->assertOk()->assertJsonPath('execution.status', 'failed');
        Mail::assertNothingSent();
    }
}
