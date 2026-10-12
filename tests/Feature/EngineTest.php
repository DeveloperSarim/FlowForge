<?php

namespace Tests\Feature;

use App\Domain\Engine\PollerService;
use App\Domain\Integrations\IntegrationManifestSchema;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\OpenApiImporter;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EngineTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): Workflow
    {
        $this->seed();

        return Workflow::query()->sole();
    }

    public function test_webhook_run_goes_through_the_database_queue_and_follows_the_true_branch(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['jsonplaceholder.typicode.com/*' => Http::response(['id' => 101], 201)]);
        $workflow = $this->demo();
        $workflow->update(['is_active' => true]);

        $this->postJson("/api/webhook/{$workflow->webhook_token}", ['name' => 'Ada', 'email' => 'ada@x.io', 'plan' => 'pro'])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('pending', WorkflowExecution::query()->sole()->status);

        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertExitCode(0);

        $execution = WorkflowExecution::query()->sole();
        $steps = collect($execution->steps)->keyBy('node_id');

        $this->assertSame('completed', $execution->status, (string) $execution->error);
        $this->assertSame('true', $steps['step_1']['handle']);
        $this->assertSame('success', $steps['step_2']['status']);
        $this->assertSame(201, $steps['step_2']['http_status']);
        $this->assertSame(['id' => 101], $steps['step_2']['output']);
        $this->assertSame('skipped', $steps['step_3']['status']);
        $this->assertDatabaseCount('jobs', 0);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['body'] === 'Ada <ada@x.io>');
    }

    public function test_editor_test_run_is_synchronous_and_takes_the_false_branch(): void
    {
        $workflow = $this->demo();
        $graph = $workflow->graph;
        $graph['nodes'][0]['data']['config']['sample_payload'] = '{"name": "Grace", "plan": "free"}';
        $workflow->update(['graph' => $graph]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson("/workflows/{$workflow->id}/test")
            ->assertOk();

        $steps = collect($response->json('execution.steps'))->keyBy('node_id');
        $this->assertSame('completed', $response->json('execution.status'));
        $this->assertSame('skipped', $steps['step_2']['status']);
        $this->assertSame('WELCOME ABOARD, GRACE!', $steps['step_3']['output']['text']);
        Http::assertNothingSent();
    }

    public function test_failed_http_step_fails_the_run_and_masks_credential_secrets(): void
    {
        Http::fake(['api.test/*' => Http::response(['error' => 'invalid key sk_live_supersecret'], 401)]);
        $credential = Credential::query()->create(['name' => 'Key', 'integration' => 'http', 'auth_type' => 'bearer', 'data' => ['token' => 'sk_live_supersecret']]);

        $workflow = Workflow::query()->create(['name' => 'Fails', 'graph' => [
            'nodes' => [
                Workflow::defaultGraph()['nodes'][0],
                ['id' => 'step_1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'integration' => 'http', 'action' => 'request', 'credential_id' => $credential->id,
                    'config' => ['method' => 'GET', 'url' => 'https://api.test/me'],
                ]],
                ['id' => 'step_2', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'integration' => 'transform', 'action' => 'text_format', 'config' => ['text' => 'never'],
                ]],
            ],
            'edges' => [['source' => 'trigger', 'target' => 'step_1'], ['source' => 'step_1', 'target' => 'step_2']],
        ]]);

        $execution = $workflow->start('test', ['data' => []], sync: true);
        $steps = collect($execution->steps)->keyBy('node_id');

        $this->assertSame('failed', $execution->status);
        $this->assertSame(401, $steps['step_1']['http_status']);
        $this->assertSame('skipped', $steps['step_2']['status']);
        $this->assertStringNotContainsString('sk_live_supersecret', json_encode($execution->steps).$execution->error);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_live_supersecret'));
    }

    public function test_saving_a_graph_with_a_cycle_is_rejected(): void
    {
        $workflow = $this->demo();
        $graph = $workflow->graph;
        $graph['edges'][] = ['source' => 'step_2', 'target' => 'step_1'];

        $this->actingAs(User::factory()->create())
            ->putJson("/workflows/{$workflow->id}", ['graph' => $graph])
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph');
    }

    public function test_schedule_trigger_fires_once_per_due_minute(): void
    {
        config(['queue.default' => 'database']);
        $workflow = Workflow::query()->create(['name' => 'Cron', 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'schedule', 'trigger' => 'cron', 'config' => ['cron' => '*/5 * * * *']],
        ]], 'edges' => []]]);
        $this->assertSame('schedule', $workflow->trigger_type);

        $poller = app(PollerService::class);
        $due = Carbon::parse('2026-10-11 10:05:00');

        $this->assertSame(1, $poller->dispatchSchedules($due));
        $this->assertSame(0, $poller->dispatchSchedules($due->copy()->addSeconds(30))); // same minute
        $this->assertSame(0, $poller->dispatchSchedules(Carbon::parse('2026-10-11 10:06:00'))); // not due
        $this->assertSame(1, $workflow->executions()->count());
    }

    public function test_polling_trigger_records_a_baseline_then_starts_one_run_per_new_item(): void
    {
        config(['queue.default' => 'database']);
        Http::fakeSequence('api.github.com/*')
            ->push([['id' => 2, 'title' => 'B'], ['id' => 1, 'title' => 'A']])
            ->push([['id' => 4, 'title' => 'D'], ['id' => 3, 'title' => 'C'], ['id' => 2, 'title' => 'B']]);

        $credential = Credential::query()->create(['name' => 'GH', 'integration' => 'github', 'auth_type' => 'bearer', 'data' => ['token' => 'ghp_x']]);
        $workflow = Workflow::query()->create(['name' => 'Issues', 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'github', 'trigger' => 'new_issue', 'credential_id' => $credential->id, 'config' => ['owner' => 'acme', 'repo' => 'app']],
        ]], 'edges' => []]]);

        $poller = app(PollerService::class);
        $this->assertSame(0, $poller->poll($workflow));        // baseline: nothing replayed
        $this->assertSame(2, $poller->poll($workflow->fresh()));

        $this->assertSame(['C', 'D'], $workflow->executions()->orderBy('id')->get()->pluck('trigger_payload.data.title')->all());
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/repos/acme/app/issues?'));
    }

    public function test_manifests_are_validated_and_openapi_documents_are_ingested(): void
    {
        $dir = sys_get_temp_dir().'/ff-manifests-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/bad.json", json_encode(['key' => 'Bad Key!', 'name' => 'Bad', 'actions' => [['key' => 'x', 'name' => 'X']]]));
        copy(resource_path('integrations/openapi/jsonplaceholder.yaml'), "$dir/api.yaml");

        $loader = new ManifestLoader(new IntegrationManifestSchema, new OpenApiImporter, $dir, "$dir/cache");

        $this->assertArrayHasKey('bad.json', $loader->errors());
        $this->assertNull($loader->get('Bad Key!'));
        $this->assertSame('/posts/{{ inputs.id }}', $loader->action('jsonplaceholder', 'get_post')['endpoint']);
        $this->assertSame(['title' => '{{ inputs.title }}', 'body' => '{{ inputs.body }}', 'userId' => '{{ inputs.userId }}'], $loader->action('jsonplaceholder', 'create_post')['body']);

        File::deleteDirectory($dir);
    }
}
