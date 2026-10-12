<?php

namespace Tests\Feature;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Demo\DemoMode;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['flowforge.demo' => true, 'app.url' => 'https://demo.example.com']);
        @unlink(storage_path('app/demo-snapshot.json'));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/demo-snapshot.json'));
        parent::tearDown();
    }

    public function test_the_sign_in_page_offers_the_demo_login(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('demo.email', 'demo@demo.example.com')->where('demo.password', 'demo12345'));
    }

    public function test_demo_mode_is_off_unless_the_env_flag_is_set(): void
    {
        config(['flowforge.demo' => false]);
        $this->get('/login')->assertInertia(fn ($page) => $page->where('demo', null));
    }

    public function test_visitors_can_build_but_not_delete_run_or_touch_credentials_and_settings(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Showcase', 'graph' => Workflow::defaultGraph()]);
        $visitor = app(DemoMode::class)->ensureUser();
        $this->actingAs($visitor);

        $this->post('/workflows', ['name' => 'Mine'])->assertRedirect();
        $this->assertSame(2, Workflow::query()->count(), 'visitors can create workflows');
        $this->putJson("/workflows/{$workflow->id}", ['name' => 'Edited'])->assertOk();
        $this->putJson("/workflows/{$workflow->id}", ['is_active' => true])->assertForbidden();
        $this->postJson("/workflows/{$workflow->id}/test")->assertForbidden();
        $this->deleteJson("/workflows/{$workflow->id}")->assertForbidden();
        $this->postJson('/credentials', ['name' => 'x'])->assertForbidden();
        $this->putJson('/settings/mail', [])->assertForbidden();
        $this->postJson('/settings/tokens', ['name' => 'x'])->assertForbidden();
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->where('mail', null)->where('auth.user.demo', true));

        [$output, , $isError] = app(WorkflowTools::class)->call('delete_workflow', ['id' => $workflow->id], new AiConversation);
        $this->assertTrue($isError);
        $this->assertStringContainsString('turned off in the live demo', $output['error']);
        $this->assertNotNull($workflow->fresh());
    }

    public function test_the_owner_is_not_restricted_and_resets_restore_the_starting_point(): void
    {
        $showcase = Workflow::query()->create(['name' => 'Showcase', 'graph' => Workflow::defaultGraph()]);
        $demo = app(DemoMode::class);
        $this->assertSame(1, $demo->snapshot());

        $this->actingAs($demo->ensureUser());
        $this->post('/workflows', ['name' => 'Visitor junk'])->assertRedirect();
        $this->putJson("/workflows/{$showcase->id}", ['name' => 'Renamed by a visitor'])->assertOk();

        $demo->reset();
        $this->assertSame(['Showcase'], Workflow::query()->pluck('name')->all());

        $this->actingAs(User::factory()->owner()->create());
        $this->delete("/workflows/{$showcase->id}")->assertRedirect();
        $this->assertNull($showcase->fresh());
    }
}
