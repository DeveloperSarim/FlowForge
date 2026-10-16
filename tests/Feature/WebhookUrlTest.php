<?php

namespace Tests\Feature;

use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_leaked_webhook_url_can_be_replaced_and_the_old_one_stops_working(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Hook', 'is_active' => true, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'webhook', 'trigger' => 'catch', 'config' => []],
        ]], 'edges' => []]]);
        $old = $workflow->webhookUrl();

        $this->actingAs(User::factory()->member(['workflows.view'])->create())
            ->postJson("/workflows/{$workflow->id}/webhook-url")->assertForbidden();

        $new = $this->actingAs(User::factory()->create())
            ->postJson("/workflows/{$workflow->id}/webhook-url")->assertOk()->json('webhook_url');

        $this->assertNotSame($old, $new);
        $this->postJson($old, ['a' => 1])->assertNotFound();
        $this->postJson($new, ['a' => 1])->assertOk();
    }
}
