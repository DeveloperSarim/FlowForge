<?php

namespace Tests\Feature;

use App\Domain\Engine\GraphRunner;
use App\Domain\Engine\Jobs\RetryExecution;
use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\Engine\PollerService;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/** Crashes, timeouts and races: nothing is lost, nothing is sent twice by FlowForge on its own. */
class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->actingAs(User::factory()->create());
    }

    private function pollingWorkflow(): Workflow
    {
        $credential = Credential::query()->create(['name' => 'GH', 'integration' => 'github', 'auth_type' => 'bearer', 'data' => ['token' => 'ghp_x']]);

        return Workflow::query()->create(['name' => 'Issues', 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'github', 'trigger' => 'new_issue', 'credential_id' => $credential->id, 'config' => ['owner' => 'acme', 'repo' => 'app']],
        ]], 'edges' => []]]);
    }

    /** trigger → step_1 (POST /a) → step_2 ($method /b, with optional step settings). */
    private function workflow(string $method = 'POST', array $step2 = [], array $settings = []): Workflow
    {
        $http = fn (string $id, string $verb, string $path, array $extra = []) => ['id' => $id, 'type' => 'action', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'http', 'action' => 'request', 'label' => $id, 'config' => ['method' => $verb, 'url' => "https://api.test/{$path}"]] + $extra];

        return Workflow::query()->create(['name' => 'Orders', 'is_active' => true, 'settings' => $settings ?: null, 'graph' => GraphValidator::normalize(['nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'webhook', 'trigger' => 'catch', 'config' => []]],
            $http('step_1', 'POST', 'a'),
            $http('step_2', $method, 'b', $step2),
        ], 'edges' => [['source' => 'trigger', 'target' => 'step_1'], ['source' => 'step_1', 'target' => 'step_2']]])]);
    }

    private function execute(Workflow $workflow): WorkflowExecution
    {
        $execution = $workflow->executions()->create(['status' => WorkflowExecution::PENDING, 'mode' => 'webhook', 'trigger_payload' => ['data' => []]]);
        (new RunWorkflow($execution->id))->handle(app(GraphRunner::class));

        return $execution->refresh();
    }

    private function step(WorkflowExecution $execution, string $id): array
    {
        return collect($execution->steps)->firstWhere('node_id', $id);
    }

    public function test_a_crash_while_starting_polled_runs_loses_nothing_and_the_next_poll_picks_the_items_up(): void
    {
        config(['queue.default' => 'database']);
        Http::fakeSequence('api.github.com/*')
            ->push([['id' => 1, 'title' => 'A']])
            ->push([['id' => 3, 'title' => 'C'], ['id' => 2, 'title' => 'B'], ['id' => 1, 'title' => 'A']])
            ->push([['id' => 3, 'title' => 'C'], ['id' => 2, 'title' => 'B'], ['id' => 1, 'title' => 'A']]);
        $workflow = $this->pollingWorkflow();
        $poller = app(PollerService::class);
        $poller->poll($workflow); // baseline

        // The worker dies after the first new run is created, before the second.
        WorkflowExecution::creating(function () {
            static $created = 0;
            if (++$created === 2) {
                throw new RuntimeException('worker killed');
            }
        });
        try {
            $poller->poll($workflow->fresh());
            $this->fail('The crash should surface.');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $workflow->executions()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(['1'], $workflow->fresh()->trigger_state['seen']); // B and C are still unseen

        WorkflowExecution::flushEventListeners();
        $this->assertSame(2, $poller->poll($workflow->fresh()));
        $this->assertSame(['B', 'C'], $workflow->executions()->orderBy('id')->get()->pluck('trigger_payload.data.title')->all());
        $this->assertSame(2, DB::table('jobs')->count()); // each run has its queue job
    }

    public function test_writing_trigger_state_from_a_stale_copy_does_not_undo_newer_state(): void
    {
        $workflow = $this->pollingWorkflow();
        $stale = Workflow::query()->find($workflow->id);
        $workflow->mergeTriggerState(['seen' => ['1', '2']]);

        $stale->mergeTriggerState(['last_error' => null]); // what PollWorkflow does after a poll
        $this->assertSame(['1', '2'], $workflow->fresh()->trigger_state['seen']);
        $this->assertSame(['1', '2'], $stale->trigger_state['seen']);
    }

    public function test_the_step_in_flight_is_saved_before_its_request_goes_out(): void
    {
        Queue::fake();
        $seen = null;
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => function () use (&$seen) {
            $seen = collect(WorkflowExecution::query()->latest('id')->first()->steps)->pluck('status', 'node_id')->all();

            return Http::response(['ok' => true]);
        }]);

        $this->assertSame('completed', $this->execute($this->workflow())->status);
        $this->assertSame(['trigger' => 'success', 'step_1' => 'success', 'step_2' => 'running'], $seen);
    }

    public function test_a_run_whose_worker_died_mid_step_says_the_step_may_have_gone_through_and_is_not_retried(): void
    {
        Queue::fake();
        $execution = $this->workflow()->executions()->create(['status' => WorkflowExecution::RUNNING, 'mode' => 'webhook', 'trigger_payload' => [], 'steps' => [
            ['node_id' => 'trigger', 'type' => 'trigger', 'label' => 'Webhook', 'status' => 'success'],
            ['node_id' => 'step_1', 'type' => 'action', 'label' => 'Create order', 'status' => 'running'],
        ]]);

        (new RunWorkflow($execution->id))->failed(new RuntimeException('has been attempted too many times'));
        $execution->refresh();

        $this->assertSame('failed', $execution->status);
        $this->assertTrue($this->step($execution, 'step_1')['uncertain']);
        $this->assertStringStartsWith('Create order: The run stopped while this step was running', $execution->error);
        $this->assertFalse(RunWorkflow::scheduleRetry($execution));
        Queue::assertNotPushed(RetryExecution::class);
    }

    public function test_a_post_that_timed_out_or_got_a_500_is_not_sent_again_automatically(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);
        $execution = $this->execute($this->workflow('POST', ['retries' => 3], ['retry_on' => 'all']));
        $step = $this->step($execution, 'step_2');
        $this->assertSame(['failed', true], [$step['status'], $step['uncertain']]);
        $this->assertArrayNotHasKey('temporary', $step);
        $this->assertNull($execution->retry_at); // not even with "retry every failure"
        Sleep::assertNeverSlept();
        Http::assertSentCount(2);

        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response(['error' => 'oops'], 500)]);
        $this->assertNull($this->execute($this->workflow('POST'))->retry_at);
    }

    public function test_a_get_that_timed_out_is_tried_again_because_reading_twice_changes_nothing(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')->push(['ok' => true])]);
        $this->assertSame('completed', $this->execute($this->workflow('GET', ['retries' => 2]))->status);
    }

    public function test_a_post_that_could_not_connect_never_reached_the_app_and_is_retried_later(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::failedConnection('cURL error 7: Failed to connect to api.test port 443')]);
        $execution = $this->execute($this->workflow('POST'));
        $this->assertTrue($this->step($execution, 'step_2')['temporary']);
        $this->assertNotNull($execution->retry_at);
    }

    public function test_apps_that_de_duplicate_get_the_same_key_on_every_try_so_their_writes_can_be_retried(): void
    {
        Queue::fake();
        Http::fake(['api.stripe.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')->push(['id' => 'cus_1'])->push(['id' => 'cus_2'])]);
        $credential = Credential::query()->create(['name' => 'Stripe', 'integration' => 'stripe', 'auth_type' => 'bearer', 'data' => ['token' => 'sk_test_x']]);
        $workflow = Workflow::query()->create(['name' => 'Customers', 'is_active' => true, 'graph' => GraphValidator::normalize(['nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'webhook', 'trigger' => 'catch', 'config' => []]],
            ['id' => 'step_1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'stripe', 'action' => 'create_customer',
                'credential_id' => $credential->id, 'retries' => 2, 'config' => ['email' => 'a@b.c']]],
        ], 'edges' => [['source' => 'trigger', 'target' => 'step_1']]])]);

        $this->assertSame('completed', $this->execute($workflow)->status); // the timed-out POST was tried again
        $this->execute($workflow);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null);
        $this->assertCount(3, $keys);
        $this->assertSame($keys[0], $keys[1]); // both tries of one run
        $this->assertNotSame($keys[1], $keys[2]); // a new run is a new customer
        $this->assertSame(64, strlen($keys[0]));
    }

    public function test_a_waiting_automatic_retry_runs_once_and_not_after_a_retry_by_hand(): void
    {
        Queue::fake();
        Http::fake(['api.test/a' => Http::response(['id' => 1]), 'api.test/b' => Http::response([], 503)]);
        $failed = $this->execute($this->workflow());
        $this->assertNotNull($failed->retry_at);

        (new RetryExecution($failed->id))->handle();
        (new RetryExecution($failed->id))->handle(); // a second copy of the job
        $this->assertSame(1, WorkflowExecution::query()->where('retry_of', $failed->id)->count());

        $again = $this->execute($this->workflow());
        $this->post("/executions/{$again->id}/retry", ['from' => 'start'])->assertRedirect();
        (new RetryExecution($again->id))->handle();
        $this->assertSame(0, WorkflowExecution::query()->where('retry_of', $again->id)->count());
    }

    public function test_a_long_job_is_never_handed_to_a_second_worker(): void
    {
        $worker = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'queue:work'));
        preg_match('/--timeout=(\d+)/', $worker->command, $m);
        $timeout = (int) $m[1];

        // The database queue hands a job to another worker after retry_after; the overlap lock must outlive a job too.
        $this->assertGreaterThan($timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($timeout, $worker->expiresAt * 60);
        $this->assertLessThanOrEqual($timeout, (new RunWorkflow(1))->timeout);
    }
}
