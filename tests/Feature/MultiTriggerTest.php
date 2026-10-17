<?php

namespace Tests\Feature;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Engine\GraphRunner;
use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\Engine\PollerService;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Several triggers in one workflow: each starts its own flow, with its own URL, schedule and state. */
class MultiTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function trigger(string $id, string $app, string $op, array $config = [], int $y = 0): array
    {
        return ['id' => $id, 'type' => 'trigger', 'position' => ['x' => 0, 'y' => $y], 'data' => ['integration' => $app, 'trigger' => $op, 'label' => $id, 'config' => $config]];
    }

    private function httpStep(string $id, string $path, int $y = 0): array
    {
        return ['id' => $id, 'type' => 'action', 'position' => ['x' => 300, 'y' => $y],
            'data' => ['integration' => 'http', 'action' => 'request', 'label' => $id, 'config' => ['method' => 'POST', 'url' => "https://api.test/{$path}"]]];
    }

    /** Main webhook → step_1 (/a); trigger_2 webhook → step_2 (/b); trigger_3 every 5 minutes → step_3 (/c). */
    private function workflow(bool $active = true): Workflow
    {
        return Workflow::query()->create(['name' => 'Three doors', 'is_active' => $active, 'graph' => GraphValidator::normalize(['nodes' => [
            $this->trigger('trigger', 'webhook', 'catch'), $this->httpStep('step_1', 'a'),
            $this->trigger('trigger_2', 'webhook', 'catch', [], 400), $this->httpStep('step_2', 'b', 400),
            $this->trigger('trigger_3', 'schedule', 'cron', ['cron' => '*/5 * * * *'], 800), $this->httpStep('step_3', 'c', 800),
        ], 'edges' => [
            ['source' => 'trigger', 'target' => 'step_1'], ['source' => 'trigger_2', 'target' => 'step_2'], ['source' => 'trigger_3', 'target' => 'step_3'],
        ]])]);
    }

    private function statuses(WorkflowExecution $execution): array
    {
        return collect($execution->steps)->pluck('status', 'node_id')->all();
    }

    public function test_each_webhook_trigger_has_its_own_url_and_runs_only_its_own_flow(): void
    {
        Http::fake(['api.test/*' => Http::response(['ok' => true])]);
        $workflow = $this->workflow();
        $this->assertSame(['webhook', ',webhook,schedule,'], [$workflow->trigger_type, $workflow->trigger_types]);
        $this->assertStringEndsWith('/trigger_2', $workflow->webhookUrl('trigger_2'));

        $this->postJson($workflow->webhookUrl(), ['n' => 1])->assertOk();
        $main = $workflow->executions()->latest('id')->first();
        $this->assertNull($main->trigger_node);
        $this->assertSame(['trigger' => 'success', 'step_1' => 'success', 'trigger_2' => 'skipped', 'step_2' => 'skipped', 'trigger_3' => 'skipped', 'step_3' => 'skipped'],
            array_intersect_key($this->statuses($main), array_flip(['trigger', 'step_1', 'trigger_2', 'step_2', 'trigger_3', 'step_3'])));

        $this->postJson($workflow->webhookUrl('trigger_2'), ['n' => 2])->assertOk();
        $second = $workflow->executions()->latest('id')->first();
        $this->assertSame('trigger_2', $second->trigger_node);
        $this->assertSame(['trigger' => 'skipped', 'step_1' => 'skipped', 'trigger_2' => 'success', 'step_2' => 'success'],
            array_intersect_key($this->statuses($second), array_flip(['trigger', 'step_1', 'trigger_2', 'step_2'])));
        $this->assertSame(2, collect($second->steps)->firstWhere('node_id', 'trigger_2')['output']['data']['n']);

        $this->assertSame(['https://api.test/a', 'https://api.test/b'], collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all());
        $this->postJson($workflow->webhookUrl('trigger_3'))->assertNotFound(); // a schedule, not a webhook
        $this->postJson($workflow->webhookUrl().'/trigger_9')->assertNotFound();
    }

    public function test_samples_and_test_runs_belong_to_the_trigger_of_the_flow(): void
    {
        Queue::fake();
        Http::fake(['api.test/*' => Http::response(['ok' => true])]);
        $workflow = $this->workflow(active: false);

        $this->postJson($workflow->webhookUrl('trigger_2'), ['email' => 'b@test'])->assertJsonPath('status', 'captured');
        $workflow->refresh();
        $this->assertSame('b@test', $workflow->triggerState('trigger_2')['sample']['data']['email']);
        $this->assertArrayNotHasKey('sample', $workflow->triggerState());

        $execution = $this->postJson("/workflows/{$workflow->id}/test", ['node' => 'step_2'])->assertOk()->json('execution');
        $run = WorkflowExecution::query()->find($execution['id']);
        $this->assertSame(['trigger_2', 'b@test'], [$run->trigger_node, $run->trigger_payload['data']['email']]);

        $this->postJson("/workflows/{$workflow->id}/test", ['trigger' => 'trigger_3'])->assertOk();
        $this->assertSame('trigger_3', WorkflowExecution::query()->latest('id')->first()->trigger_node);
    }

    public function test_schedule_and_polling_triggers_keep_their_own_state(): void
    {
        Queue::fake();
        $workflow = $this->workflow();
        $poller = app(PollerService::class);
        $due = Carbon::parse('2026-10-11 10:05:00');

        $this->assertSame(1, $poller->dispatchSchedules($due));
        $this->assertSame(0, $poller->dispatchSchedules($due->copy()->addSeconds(20))); // same minute
        $this->assertSame('trigger_3', $workflow->executions()->sole()->trigger_node);
        $this->assertSame('2026-10-11 10:05', $workflow->fresh()->triggerState('trigger_3')['last_scheduled_minute']);
        $this->assertArrayNotHasKey('last_scheduled_minute', $workflow->fresh()->triggerState());

        Http::fakeSequence('api.github.com/*')->push([['id' => 1, 'title' => 'A']])->push([['id' => 2, 'title' => 'B'], ['id' => 1, 'title' => 'A']]);
        $credential = Credential::query()->create(['name' => 'GH', 'integration' => 'github', 'auth_type' => 'bearer', 'data' => ['token' => 'ghp_x']]);
        $issues = Workflow::query()->create(['name' => 'Issues too', 'is_active' => true, 'graph' => GraphValidator::normalize(['nodes' => [
            $this->trigger('trigger', 'manual', 'manual'),
            ['id' => 'trigger_2', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 400], 'data' => ['integration' => 'github', 'trigger' => 'new_issue', 'credential_id' => $credential->id, 'config' => ['owner' => 'acme', 'repo' => 'app']]],
        ], 'edges' => []])]);
        $this->assertSame([',manual,polling,', ['trigger_2']], [$issues->trigger_types, $issues->triggerIds('polling')]);

        $this->assertSame(0, $poller->poll($issues, 'trigger_2')); // baseline
        $this->assertSame(1, $poller->poll($issues->fresh(), 'trigger_2'));
        $this->assertSame(['2', '1'], $issues->fresh()->triggerState('trigger_2')['seen']);
        $this->assertSame('trigger_2', $issues->executions()->sole()->trigger_node);
    }

    public function test_error_triggers_and_retries_remember_which_trigger_started_the_run(): void
    {
        Queue::fake();
        Http::fake(['api.test/b' => Http::response(['error' => 'down'], 503), 'api.test/*' => Http::response(['ok' => true])]);
        $workflow = $this->workflow();
        $alerts = Workflow::query()->create(['name' => 'Alerts', 'is_active' => true, 'graph' => GraphValidator::normalize(['nodes' => [
            $this->trigger('trigger', 'manual', 'manual'), $this->trigger('trigger_2', 'error_trigger', 'workflow_failed', [], 400),
        ], 'edges' => []])]);

        $failed = $workflow->start('webhook', ['data' => []], trigger: 'trigger_2');
        (new RunWorkflow($failed->id))->handle(app(GraphRunner::class));
        $failed->refresh();
        $this->assertSame('failed', $failed->status);

        $resumed = $workflow->resume($failed, 'retry');
        $this->assertSame('trigger_2', $resumed->trigger_node);

        RunWorkflow::afterFailure($failed);
        $this->assertSame('trigger_2', $alerts->executions()->sole()->trigger_node);
    }

    public function test_the_graph_rules_and_the_ai_builder_know_about_more_triggers(): void
    {
        $nodes = [$this->trigger('trigger', 'manual', 'manual'), $this->trigger('trigger_2', 'webhook', 'catch'), $this->httpStep('step_1', 'a')];
        $refuse = function (array $graph, string $message) {
            try {
                GraphValidator::normalize($graph);
                $this->fail("Expected: {$message}");
            } catch (ValidationException $e) {
                $this->assertSame($message, $e->errors()['graph'][0]);
            }
        };
        $refuse(['nodes' => $nodes, 'edges' => [['source' => 'step_1', 'target' => 'trigger_2']]], 'Nothing can connect into a trigger.');
        $refuse(['nodes' => [$this->trigger('trigger_2', 'manual', 'manual')], 'edges' => []], 'The main trigger is missing.');
        $refuse(['nodes' => [$this->trigger('trigger', 'manual', 'manual'), $this->trigger('second', 'manual', 'manual')], 'edges' => []], 'Triggers are named "trigger", "trigger_2", "trigger_3"…');

        [$saved, , $isError] = app(WorkflowTools::class)->call('save_workflow', ['name' => 'Two ways in', 'nodes' => [
            ['id' => 'trigger', 'app' => 'manual', 'op' => 'manual'], ['id' => 'step_1', 'app' => 'transform', 'op' => 'text_format', 'config' => ['text' => 'a', 'operation' => 'trim']],
            ['id' => 'trigger_2', 'app' => 'schedule', 'op' => 'cron', 'config' => ['cron' => '0 9 * * *']], ['id' => 'step_2', 'app' => 'transform', 'op' => 'text_format', 'config' => ['text' => 'b', 'operation' => 'trim']],
        ], 'edges' => [['from' => 'trigger', 'to' => 'step_1'], ['from' => 'trigger_2', 'to' => 'step_2']]], new AiConversation);
        $this->assertFalse($isError);
        $workflow = Workflow::query()->find($saved['id']);
        $this->assertSame(['trigger', 'trigger_2'], array_column($workflow->triggerNodes(), 'id'));
        $y = collect($workflow->graph['nodes'])->mapWithKeys(fn ($n) => [$n['id'] => $n['position']['y']]);
        $this->assertGreaterThan($y['step_1'], $y['step_2']); // the second flow gets its own band below
    }
}
