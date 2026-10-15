<?php

namespace Tests\Feature;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Integrations\IntegrationManifestSchema;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\OpenApiImporter;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomAppsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Custom apps and the compiled catalog go to a throwaway folder, never the real storage or cache.
        $this->dir = sys_get_temp_dir().'/ff-custom-'.Str::random(8);
        config(['flowforge.custom_integrations_path' => $this->dir.'/apps']);
        $this->app->instance(ManifestLoader::class, new ManifestLoader(new IntegrationManifestSchema, new OpenApiImporter, null, $this->dir.'/cache', $this->dir.'/apps'));
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function tool(string $tool, array $input): array
    {
        $conversation = AiConversation::query()->firstOrCreate(['user_id' => auth()->id()], ['provider' => 'deepseek', 'model' => 'deepseek-chat']);

        return app(WorkflowTools::class)->call($tool, $input, $conversation);
    }

    private function acme(): array
    {
        return [
            'name' => 'Acme CRM', 'base_url' => 'https://api.acme.test/v2',
            // Gemini sends free-form objects as JSON strings.
            'auth' => json_encode(['type' => 'api_key', 'in' => 'header', 'name' => 'X-API-Key']),
            'actions' => [[
                'key' => 'create_contact', 'name' => 'Create contact', 'method' => 'POST', 'endpoint' => '/contacts',
                'body' => ['email' => '{{ inputs.email }}', 'phone' => '{{ inputs.phone }}'],
                'inputs' => [['key' => 'email', 'type' => 'string', 'required' => true], ['key' => 'phone', 'type' => 'string']],
                'output_fields' => json_encode(['id' => 'data.id']),
            ]],
            'triggers' => [['key' => 'new_contact', 'name' => 'New contact', 'endpoint' => '/contacts', 'query' => ['sort' => '-created'], 'items_path' => 'data']],
        ];
    }

    public function test_the_ai_builds_a_custom_app_and_a_working_workflow_with_it(): void
    {
        [$out, , $error] = $this->tool('create_custom_app', $this->acme());
        $this->assertFalse($error, json_encode($out));
        $this->assertSame(['custom_acme_crm', true, 'create_contact', 'new_contact', 'api_key'], [$out['key'], $out['created'], $out['actions'], $out['triggers'], $out['auth']]);
        $this->assertSame('custom_acme_crm', $this->tool('search_apps', ['query' => 'acme'])[0][0]['key']);
        $this->assertTrue(app(ManifestLoader::class)->action('custom_acme_crm', 'create_contact')['restrict_to_base_url']);

        $credential = Credential::query()->create(['name' => 'Acme', 'integration' => 'custom_acme_crm', 'auth_type' => 'api_key', 'data' => ['api_key' => 'acme-key']]);
        [$saved, , $error] = $this->tool('save_workflow', ['name' => 'New lead to Acme', 'nodes' => [
            ['id' => 'trigger', 'app' => 'manual', 'op' => 'manual', 'config' => ['sample_payload' => '{"email":"sara@example.com"}']],
            ['id' => 'step_1', 'app' => 'custom_acme_crm', 'op' => 'create_contact', 'credential_id' => $credential->id, 'config' => ['email' => '{{ steps.trigger.data.email }}']],
            ['id' => 'step_2', 'app' => 'transform', 'op' => 'text_format', 'config' => ['text' => 'Contact {{ steps.step_1.id }}', 'operation' => 'trim']],
        ], 'edges' => [['from' => 'trigger', 'to' => 'step_1'], ['from' => 'step_1', 'to' => 'step_2']]]);
        $this->assertFalse($error, json_encode($saved));
        $this->assertArrayNotHasKey('warnings', $saved);

        Http::fake(['api.acme.test/*' => Http::response(['data' => ['id' => 'c_42']])]);
        [$run, , $error] = $this->tool('test_workflow', ['id' => $saved['id']]);
        $this->assertFalse($error);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.acme.test/v2/contacts' && $r->hasHeader('X-API-Key', 'acme-key') && $r->data() === ['email' => 'sara@example.com']);
        $this->assertSame('completed', $run['status'], json_encode($run));

        // Building it again with the same name updates the app instead of adding a second one.
        $this->assertFalse($this->tool('create_custom_app', $this->acme())[0]['created']);
    }

    public function test_bad_custom_apps_are_explained_not_saved(): void
    {
        $bad = fn (array $patch) => $this->tool('create_custom_app', array_replace($this->acme(), $patch));

        $this->assertStringContainsString('base_url', $bad(['base_url' => '/v2'])[0]['error']);
        $this->assertStringContainsString('endpoint must be a path', $bad(['actions' => [['key' => 'steal', 'name' => 'Steal', 'method' => 'GET', 'endpoint' => 'https://evil.test/x']]])[0]['error']);
        $this->assertStringContainsString('1 to 60 actions', $bad(['actions' => []])[0]['error']);
        $this->assertStringContainsString('auth.type', $bad(['auth' => ['type' => 'mysql']])[0]['error']);
        $this->assertSame([], File::glob($this->dir.'/apps/*.json'));
    }

    public function test_an_openapi_spec_becomes_a_custom_app_that_can_be_deleted_when_unused(): void
    {
        Http::fake(['specs.test/openapi.json' => Http::response(['openapi' => '3.0.3', 'info' => ['title' => 'Pet Store', 'description' => 'Send **your key** as `Authorization: Bearer <key>`.'], 'servers' => [['url' => '/v1']],
            'paths' => ['/pets' => ['get' => ['operationId' => 'listPets', 'summary' => 'List pets'], 'post' => ['operationId' => 'createPet', 'summary' => 'Create a pet']]]])]);

        $this->post('/apps/custom', ['source' => 'https://specs.test/openapi.json'])->assertRedirect('/apps/custom_pet_store');
        $app = app(ManifestLoader::class)->get('custom_pet_store');
        $this->assertSame(['https://specs.test/v1', 'Custom apps', true], [$app['base_url'], $app['category'], $app['custom']]);
        // Markdown is kept as written (the UI renders it), and the link is kept for "Update from OpenAPI".
        $this->assertSame(['Send **your key** as `Authorization: Bearer <key>`.', 'https://specs.test/openapi.json'], [$app['description'], $app['openapi_url']]);
        $this->get('/apps/custom_pet_store')->assertOk()->assertInertia(fn ($page) => $page->where('app.custom', true)->where('app.used_by', []));

        $workflow = Workflow::query()->create(['name' => 'Pets', 'graph' => ['nodes' => [['id' => 'step_1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['integration' => 'custom_pet_store', 'action' => $app['actions'][0]['key'], 'config' => []]]], 'edges' => []]]);
        $this->delete('/apps/custom/custom_pet_store')->assertSessionHas('error');
        $this->assertNotNull(app(ManifestLoader::class)->get('custom_pet_store'));

        $workflow->delete();
        $this->delete('/apps/custom/custom_pet_store')->assertRedirect('/apps');
        $this->assertFileDoesNotExist($this->dir.'/apps/custom_pet_store.json');
        $this->delete('/apps/custom/gmail')->assertSessionHas('error'); // bundled apps can't be deleted
    }

    public function test_people_without_edit_access_cannot_add_apps(): void
    {
        $this->actingAs(User::factory()->member(['workflows.view', 'ai'])->create());

        $this->post('/apps/custom', ['source' => '{}'])->assertForbidden();
        [$out, , $error] = $this->tool('create_custom_app', $this->acme());
        $this->assertTrue($error);
        $this->assertStringContainsString('Create & edit workflows', $out['error']);
    }
}
