<?php

namespace Tests\Feature;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Integrations\Models\Credential;
use App\Domain\System\SystemMail;
use App\Domain\System\SystemSettings;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_invites_a_member_who_joins_with_their_own_password(): void
    {
        Mail::fake();
        app(SystemSettings::class)->set('mail', ['host' => 'mail.example.com', 'port' => 587, 'from_address' => 'ff@example.com']);
        $this->actingAs(User::factory()->owner()->create());

        $invite = $this->postJson('/settings/team', ['email' => 'Sara@Example.com', 'role' => 'member', 'permissions' => ['workflows.edit', 'ai']])
            ->assertOk()->assertJsonPath('emailed', true)->json();
        Mail::assertSent(SystemMail::class, fn ($mail) => $mail->hasTo('sara@example.com') && $mail->url === $invite['invite_url']);
        $member = User::query()->where('email', 'sara@example.com')->sole();
        $this->assertTrue($member->isInvited());
        $this->assertSame(['workflows.edit', 'ai'], $member->permissions);

        auth()->logout();
        $path = parse_url($invite['invite_url'], PHP_URL_PATH);
        $this->get($path)->assertInertia(fn ($page) => $page->component('Auth/AcceptInvite')->where('member.email', 'sara@example.com'));
        $this->post($path, ['name' => 'Sara Khan', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($member->fresh());
        $this->assertFalse($member->fresh()->isInvited());

        auth()->logout();
        $this->get($path)->assertInertia(fn ($page) => $page->where('member', null)); // single use
    }

    public function test_view_only_member_can_look_but_not_change_anything(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Leads', 'graph' => Workflow::defaultGraph()]);
        $this->actingAs(User::factory()->member(['workflows.view'])->create());

        $this->get('/workflows')->assertOk();
        $this->get("/workflows/{$workflow->id}")->assertOk();
        $this->get('/executions')->assertOk();
        $this->putJson("/workflows/{$workflow->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->post('/workflows', ['name' => 'New'])->assertForbidden();
        $this->delete("/workflows/{$workflow->id}")->assertForbidden();
        $this->get('/credentials')->assertForbidden();
        $this->get('/assistant')->assertForbidden();
        $this->putJson('/settings/mail', [])->assertForbidden();
        $this->postJson('/settings/team', ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->missing('team')->missing('mail'));
        $this->assertSame('Leads', $workflow->fresh()->name);
    }

    public function test_editor_edits_and_uses_credentials_but_needs_delete_access_to_delete(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Leads', 'graph' => Workflow::defaultGraph()]);
        $credential = Credential::query()->create(['name' => 'Slack', 'integration' => 'slack', 'auth_type' => 'bearer', 'data' => ['token' => 'xoxb-1']]);
        $this->actingAs(User::factory()->member(['workflows.edit'])->create());

        $this->putJson("/workflows/{$workflow->id}", ['name' => 'Leads v2'])->assertOk();
        $this->get('/credentials')->assertOk();
        $this->delete("/workflows/{$workflow->id}")->assertForbidden();
        $this->delete("/credentials/{$credential->id}")->assertForbidden();
        $this->assertSame('Leads v2', $workflow->fresh()->name);
    }

    public function test_the_assistant_acts_with_the_members_access_and_chats_are_private(): void
    {
        $owner = User::factory()->owner()->create();
        $ownersChat = AiConversation::query()->create(['user_id' => $owner->id, 'title' => 'Mine', 'provider' => '', 'model' => '', 'display' => [], 'history' => []]);
        $member = User::factory()->member(['workflows.view', 'ai'])->create();
        $this->actingAs($member);

        [$output, , $isError] = app(WorkflowTools::class)->call('save_workflow', ['name' => 'Sneaky', 'nodes' => [], 'edges' => []], new AiConversation);
        $this->assertTrue($isError);
        $this->assertStringContainsString('"Create & edit workflows"', $output['error']);
        $this->assertSame(0, Workflow::query()->count());

        $this->getJson("/assistant/conversations/{$ownersChat->id}")->assertNotFound();
    }

    public function test_the_owner_cannot_be_removed_or_changed_and_admins_cannot_change_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->deleteJson("/settings/team/{$owner->id}")->assertForbidden();
        $this->putJson("/settings/team/{$owner->id}", ['role' => 'member', 'permissions' => ['workflows.view']])->assertForbidden();
        $this->putJson("/settings/team/{$admin->id}", ['role' => 'member', 'permissions' => ['workflows.view']])->assertForbidden();
        $this->assertTrue($owner->fresh()->isOwner());
    }
}
