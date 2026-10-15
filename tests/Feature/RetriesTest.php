<?php

namespace Tests\Feature;

use App\Domain\Engine\GraphRunner;
use App\Domain\Engine\Jobs\RetryExecution;
use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\System\SystemSettings;
use App\Domain\System\WorkflowFailedMail;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RetriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->actingAs(User::factory()->create());
    }

    /** trigger → step_1 (POST /a) → step_2 (POST /b), with optional settings on step_2. */
    private function workflow(array $step2 = [], array $settings = []): Workflow
    {
        $http = fn (string $id, string $path, array $extra = []) => ['id' => $id, 'type' => 'action', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'http', 'action' => 'request', 'label' => $id, 'config' => ['method' => 'POST', 'url' => "https://api.test/{$path}"]] + $extra];

        return Workflow::query()->create(['name' => 'Sync', 'is_active' => true, 'settings' => $settings ?: null, 'graph' => GraphValidator::normalize(['nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'webhook', 'trigger' => 'catch', 'config' => []]],
            $http('step_1', 'a'),
            $http('step_2', 'b', $step2),
        ], 'edges' => [['source' => 'trigger', 'target' => 'step_1'], ['source' => 'step_1', 'target' => 'step_2']]])]);
    }

    private function execute(Workflow $workflow, string $mode = 'webhook'): WorkflowExecution
    {
        $execution = $workflow->executions()->create(['status' => WorkflowExecution::PENDING, 'mode' => $mode, 'trigger_payload' => ['data' => ['email' => 'a@b.c']]]);
        (new RunWorkflow($execution->id))->handle(app(GraphRunner::class));

        return $execution->refresh();
    }

    private function step(WorkflowExecution $execution, string $id): array
    {
        return collect($execution->steps)->firstWhere('node_id', $id);
    }

    public function test_a_step_tries_again_on_temporary_errors_but_not_on_bad_requests(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::sequence()->push(['busy' => true], 503)->push(['ok' => true])]);
        $execution = $this->execute($this->workflow(['retries' => 3]));
        $this->assertSame(['completed', 'success', 2], [$execution->status, $this->step($execution, 'step_2')['status'], $this->step($execution, 'step_2')['tries']]);
        Sleep::assertSleptTimes(1);

        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::sequence()->push(['error' => 'bad email'], 400)->push(['ok' => true])]);
        $execution = $this->execute($this->workflow(['retries' => 3]));
        $this->assertSame('failed', $execution->status);
        $this->assertArrayNotHasKey('tries', $this->step($execution, 'step_2')); // a 400 isn't retried
    }

    public function test_a_step_can_keep_the_run_going_or_be_turned_off(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response(['error' => 'nope'], 422)]);
        $workflow = $this->workflow(['on_error' => 'continue']);
        $graph = $workflow->graph;
        $graph['nodes'][] = ['id' => 'step_3', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'transform', 'action' => 'text_format', 'config' => ['text' => 'Problem: {{ steps.step_2.error }}', 'operation' => 'trim']]];
        $graph['edges'][] = ['id' => 'e3', 'source' => 'step_2', 'target' => 'step_3', 'sourceHandle' => null];
        $workflow->update(['graph' => $graph]);

        $execution = $this->execute($workflow);
        $this->assertSame('completed', $execution->status);
        $this->assertTrue($this->step($execution, 'step_2')['continued']);
        $this->assertStringContainsString('Problem: ', $this->step($execution, 'step_3')['output']['text']);

        // Turned off: skipped, and the path goes on.
        $graph['nodes'][2]['data']['disabled'] = true;
        $workflow->update(['graph' => GraphValidator::normalize($graph)]);
        $execution = $this->execute($workflow);
        $this->assertSame(['completed', 'skipped', true, 'success'], [$execution->status, $this->step($execution, 'step_2')['status'], $this->step($execution, 'step_2')['disabled'], $this->step($execution, 'step_3')['status']]);
        Http::assertSentCount(3); // step_1 twice, step_2 once (it was off the second time)
    }

    public function test_a_run_that_failed_on_a_temporary_error_is_retried_later_from_the_failed_step(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::sequence()->push([], 503)->push(['ok' => true])]);
        $failed = $this->execute($this->workflow());

        $this->assertSame('failed', $failed->status);
        $this->assertTrue($this->step($failed, 'step_2')['temporary']);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $failed->retry_at->timestamp, 5);
        Queue::assertPushed(RetryExecution::class, fn ($job) => $job->executionId === $failed->id && $job->delay !== null);

        (new RetryExecution($failed->id))->handle();
        $retry = WorkflowExecution::query()->where('retry_of', $failed->id)->sole();
        $this->assertSame(['auto_retry', 2], [$retry->mode, $retry->attempt]);
        $this->assertNull($failed->refresh()->retry_at);

        (new RunWorkflow($retry->id))->handle(app(GraphRunner::class));
        $retry->refresh();
        $this->assertSame('completed', $retry->status);
        $this->assertTrue($this->step($retry, 'step_1')['reused']); // not sent again
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === 'https://api.test/a')->count());

        // A second automatic retry of the same failure does nothing.
        (new RetryExecution($failed->id))->handle();
        $this->assertSame(1, WorkflowExecution::query()->where('retry_of', $failed->id)->count());
    }

    public function test_the_failure_email_waits_for_the_last_try(): void
    {
        Queue::fake();
        Mail::fake();
        app(SystemSettings::class)->set('mail', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'ff@example.com']);
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response([], 503)]);
        $workflow = $this->workflow();

        $this->assertNotNull($this->execute($workflow)->retry_at);
        Mail::assertNothingSent();

        $last = $workflow->executions()->create(['status' => WorkflowExecution::PENDING, 'mode' => 'auto_retry', 'attempt' => 6, 'trigger_payload' => []]);
        (new RunWorkflow($last->id))->handle(app(GraphRunner::class));
        Mail::assertSent(WorkflowFailedMail::class, 1);
    }

    public function test_runs_are_not_retried_when_switched_off_tested_by_hand_or_out_of_tries(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response([], 503)]);

        $this->assertNull($this->execute($this->workflow([], ['auto_retry' => false]))->retry_at);
        $this->assertNull($this->execute($this->workflow(), 'test')->retry_at);

        $last = $this->workflow()->executions()->create(['status' => WorkflowExecution::PENDING, 'mode' => 'auto_retry', 'attempt' => 6, 'trigger_payload' => []]);
        (new RunWorkflow($last->id))->handle(app(GraphRunner::class));
        $this->assertNull($last->refresh()->retry_at);
        Queue::assertNotPushed(RetryExecution::class);

        // A workflow switched off while a retry waits isn't run.
        $workflow = $this->workflow();
        $waiting = $this->execute($workflow);
        $workflow->update(['is_active' => false]);
        (new RetryExecution($waiting->id))->handle();
        $this->assertSame(0, WorkflowExecution::query()->where('retry_of', $waiting->id)->count());
        $this->assertNull($waiting->refresh()->retry_at);
    }

    public function test_retry_by_hand_resumes_from_the_failed_step_or_starts_over(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response(['error' => 'bad'], 400)]);
        $failed = $this->execute($this->workflow());
        $this->assertNull($failed->retry_at); // a 400 isn't retried automatically

        $this->post("/executions/{$failed->id}/retry", ['from' => 'failed'])->assertRedirect()->assertSessionHas('success');
        $resumed = WorkflowExecution::query()->latest('id')->first();
        $this->assertSame(['retry', $failed->id, 2], [$resumed->mode, $resumed->retry_of, $resumed->attempt]);

        $this->post("/executions/{$failed->id}/retry", ['from' => 'start'])->assertRedirect();
        $this->assertNull(WorkflowExecution::query()->latest('id')->first()->retry_of);
    }

    public function test_a_workflow_can_retry_every_failure_and_be_switched_from_the_list(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response(['error' => 'bad'], 400)]);
        $workflow = $this->workflow([], ['retry_on' => 'all']);
        $this->assertNotNull($this->execute($workflow)->retry_at); // a 400 is retried too

        $this->putJson("/workflows/{$workflow->id}", ['settings' => ['retry_on' => 'sometimes']])->assertStatus(422);
        $this->putJson("/workflows/{$workflow->id}", ['settings' => ['auto_retry' => false]])->assertOk();
        $this->assertSame(['retry_on' => 'all', 'auto_retry' => false], $workflow->refresh()->settings);
        $this->get('/workflows')->assertInertia(fn ($page) => $page->where('workflows.0.auto_retry', false));
        $this->get("/workflows/{$workflow->id}")->assertInertia(fn ($page) => $page->where('workflow.settings', ['auto_retry' => false, 'retry_on' => 'all']));
    }

    public function test_step_settings_are_cleaned_and_the_workflow_setting_is_saved(): void
    {
        $graph = GraphValidator::normalize(['nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'manual', 'trigger' => 'manual', 'disabled' => true]],
            ['id' => 'step_1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'http', 'action' => 'request', 'retries' => 9, 'on_error' => 'maybe', 'disabled' => 'true']],
        ], 'edges' => []]);
        $this->assertArrayNotHasKey('disabled', $graph['nodes'][0]['data']); // a trigger can't be turned off
        $this->assertSame([5, true], [$graph['nodes'][1]['data']['retries'], $graph['nodes'][1]['data']['disabled']]);
        $this->assertArrayNotHasKey('on_error', $graph['nodes'][1]['data']);

        $workflow = $this->workflow();
        $this->putJson("/workflows/{$workflow->id}", ['settings' => ['auto_retry' => false]])->assertOk();
        $this->assertFalse($workflow->refresh()->autoRetries());
    }
}
