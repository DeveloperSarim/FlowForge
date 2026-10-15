<?php

namespace Tests\Feature;

use App\Domain\Integrations\CustomApps;
use App\Domain\Integrations\IntegrationManifestSchema;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\OpenApiImporter;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowPortTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ff-port-'.Str::random(8);
        config(['flowforge.custom_integrations_path' => $this->dir.'/apps']);
        $this->app->instance(ManifestLoader::class, new ManifestLoader(new IntegrationManifestSchema, new OpenApiImporter, null, $this->dir.'/cache', $this->dir.'/apps'));
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function workflow(string $name, array $nodes, array $edges = []): Workflow
    {
        return Workflow::query()->create(['name' => $name, 'graph' => ['nodes' => $nodes, 'edges' => $edges]]);
    }

    private function node(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => $data];
    }

    public function test_a_workflow_round_trips_through_a_file_without_its_secrets(): void
    {
        app(CustomApps::class)->save(['name' => 'Acme CRM', 'base_url' => 'https://api.acme.test', 'auth' => ['type' => 'bearer'], 'actions' => [[
            'key' => 'login', 'name' => 'Log in', 'method' => 'POST', 'endpoint' => '/login', 'body' => ['user' => '{{ inputs.user }}', 'pin' => '{{ inputs.pin }}'],
            'inputs' => [['key' => 'user', 'type' => 'string'], ['key' => 'pin', 'type' => 'password']],
        ]]]);
        $credential = Credential::query()->create(['name' => 'Acme', 'integration' => 'custom_acme_crm', 'auth_type' => 'bearer', 'data' => ['token' => 't']]);
        $workflow = $this->workflow('Sync Acme', [
            $this->node('trigger', 'trigger', ['integration' => 'manual', 'trigger' => 'manual', 'config' => []]),
            $this->node('step_1', 'action', ['integration' => 'custom_acme_crm', 'action' => 'login', 'credential_id' => $credential->id, 'config' => ['user' => 'sara', 'pin' => '1234']]),
        ], [['id' => 'e1', 'source' => 'trigger', 'target' => 'step_1', 'sourceHandle' => null]]);

        $response = $this->get("/workflows/{$workflow->id}/export")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="sync-acme.flowforge.json"');
        $file = $response->getContent();
        $document = json_decode($file, true);
        $this->assertSame('flowforge.workflows', $document['format']);
        $this->assertArrayNotHasKey('credential_id', $document['workflows'][0]['graph']['nodes'][1]['data']);
        $this->assertSame(['user' => 'sara', 'pin' => ''], $document['workflows'][0]['graph']['nodes'][1]['data']['config']);
        $this->assertSame('custom_acme_crm', $document['custom_apps'][0]['key']);

        // On another install: no workflow and no custom app yet, but one Acme credential.
        $workflow->delete();
        File::delete($this->dir.'/apps/custom_acme_crm.json');
        app(ManifestLoader::class)->flush();

        $this->post('/workflows/import', ['document' => $file])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Added the custom app Acme CRM'));
        $imported = Workflow::query()->sole();
        $this->assertSame(['Sync Acme', false, $credential->id], [$imported->name, $imported->is_active, $imported->graph['nodes'][1]['data']['credential_id']]);
        $this->assertNotNull(app(ManifestLoader::class)->get('custom_acme_crm'));

        // Importing it again keeps both, with a clear name.
        $this->post('/workflows/import', ['document' => $file])->assertRedirect();
        $this->assertSame(['Sync Acme', 'Sync Acme (imported)'], Workflow::query()->orderBy('id')->pluck('name')->all());
        $this->get('/workflows/export')->assertOk()->assertJsonCount(2, 'workflows');
    }

    public function test_bad_files_are_explained_and_n8n_files_go_to_the_ai_builder(): void
    {
        $this->post('/workflows/import', ['document' => 'nope'])->assertSessionHasErrors(['document' => 'The file isn\'t valid JSON.']);
        $this->post('/workflows/import', ['document' => '{"format":"other"}'])->assertSessionHasErrors('document');
        $twoTriggers = ['format' => 'flowforge.workflows', 'workflows' => [['name' => 'Bad', 'graph' => ['nodes' => [
            $this->node('trigger', 'trigger', ['integration' => 'manual', 'trigger' => 'manual']), $this->node('t2', 'trigger', ['integration' => 'manual', 'trigger' => 'manual']),
        ], 'edges' => []]]]];
        $this->post('/workflows/import', ['document' => json_encode($twoTriggers)])->assertSessionHasErrors(['document' => '"Bad": A workflow needs exactly one trigger.']);
        $this->assertSame(0, Workflow::query()->count());

        $n8n = ['name' => 'Leads', 'connections' => [], 'nodes' => [
            ['name' => 'Webhook', 'type' => 'n8n-nodes-base.webhook', 'parameters' => ['path' => 'lead']],
            ['name' => 'Tell sales', 'type' => 'n8n-nodes-base.slack', 'parameters' => ['resource' => 'message', 'operation' => 'post']],
        ]];
        $redirect = $this->post('/workflows/import', ['document' => json_encode($n8n)])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/assistant?prompt=', $redirect);
        $this->assertStringContainsString('Tell sales (Slack; resource: message, operation: post)', rawurldecode($redirect));

        // The editor's older "Download as JSON" files still import.
        $legacy = ['name' => 'Old one', 'nodes' => [$this->node('trigger', 'trigger', ['integration' => 'manual', 'trigger' => 'manual'])], 'edges' => []];
        $this->post('/workflows/import', ['document' => json_encode($legacy)])->assertRedirect();
        $this->assertSame('Old one', Workflow::query()->sole()->name);
    }

    public function test_viewers_can_export_but_only_editors_can_import(): void
    {
        $workflow = $this->workflow('Mine', [$this->node('trigger', 'trigger', ['integration' => 'manual', 'trigger' => 'manual'])]);
        $this->actingAs(User::factory()->member(['workflows.view'])->create());

        $this->get("/workflows/{$workflow->id}/export")->assertOk();
        $this->post('/workflows/import', ['document' => '{}'])->assertForbidden();
    }
}
