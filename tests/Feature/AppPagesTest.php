<?php

namespace Tests\Feature;

use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AppPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_pages_render_for_the_admin(): void
    {
        $this->seed();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Overview')->has('metrics.current')->has('series', 14));

        $this->actingAs($user)->get('/apps')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Apps/Index')->where('apps', fn ($apps) => count($apps) >= 500));

        $this->actingAs($user)->get('/apps/slack')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Apps/Show')->where('app.key', 'slack'));

        foreach (['/workflows', '/templates', '/executions', '/credentials', '/settings'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }

    public function test_each_settings_section_has_its_own_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/updates')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings')->where('tab', 'updates')->has('updates.version'));
        $this->actingAs($user)->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'account'));
        $this->actingAs($user)->get('/settings?tab=ai')->assertRedirect('/settings/ai'); // old links
        $this->actingAs($user)->get('/settings/nope')->assertNotFound();
    }

    public function test_using_a_template_creates_a_workflow(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/templates/lead-to-hubspot-slack/use')
            ->assertRedirect();

        $workflow = Workflow::query()->sole();
        $this->assertSame('webhook', $workflow->trigger_type);
        $this->assertSame(['hubspot', 'slack'], collect($workflow->graph['nodes'])->pluck('data.integration')->filter()->slice(1)->values()->all());
    }

    public function test_execute_step_only_runs_the_step_and_its_ancestors(): void
    {
        $this->seed();
        $workflow = Workflow::query()->sole();

        $response = $this->actingAs(User::factory()->create())
            ->postJson("/workflows/{$workflow->id}/test", ['node' => 'step_1'])
            ->assertOk();

        $steps = collect($response->json('execution.steps'))->keyBy('node_id');
        $this->assertSame('success', $steps['step_1']['status']);
        $this->assertSame('skipped', $steps['step_2']['status']);
        $this->assertSame('skipped', $steps['step_3']['status']);
        Http::assertNothingSent();
    }

    public function test_webhook_answers_provider_verification_handshakes(): void
    {
        $this->seed();
        $token = Workflow::query()->sole()->webhook_token;

        $this->get("/api/webhook/{$token}?hub.mode=subscribe&hub.challenge=12345&hub.verify_token=x")->assertOk()->assertSee('12345');
        $this->postJson("/api/webhook/{$token}", ['type' => 'url_verification', 'challenge' => 'abc'])->assertOk()->assertJson(['challenge' => 'abc']);
        $this->postJson("/api/webhook/{$token}", [], ['X-Hook-Secret' => 's3cret'])->assertOk()->assertHeader('X-Hook-Secret', 's3cret');
        $this->assertSame(0, Workflow::query()->sole()->executions()->count());
    }

    public function test_appearance_setting_is_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/appearance', ['theme' => 'dark'])->assertRedirect();

        $this->assertSame('dark', $user->fresh()->theme);
    }
}
