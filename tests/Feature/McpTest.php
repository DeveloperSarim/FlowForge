<?php

namespace Tests\Feature;

use App\Domain\Workflows\Models\Workflow;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(string $token, string $method, array $params = [], ?int $id = 1)
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method] + ($params ? ['params' => $params] : []) + ($id !== null ? ['id' => $id] : []);

        return $this->postJson('/api/mcp', $message, ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream']);
    }

    public function test_an_agent_connects_lists_tools_and_builds_a_workflow(): void
    {
        [, $token] = ApiToken::issue(User::factory()->owner()->create(), 'Claude Code');

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-03-26')->assertJsonPath('result.serverInfo.name', 'flowforge')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->rpc($token, 'notifications/initialized', id: null)->assertStatus(202);

        $tools = collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools['get_app']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['delete_workflow']['annotations']['destructiveHint']);

        $result = $this->rpc($token, 'tools/call', ['name' => 'save_workflow', 'arguments' => [
            'name' => 'From Claude Code',
            'nodes' => [['id' => 'trigger', 'app' => 'manual', 'op' => 'manual'], ['id' => 'step_1', 'app' => 'transform', 'op' => 'text_format', 'config' => ['text' => 'hi']]],
            'edges' => [['from' => 'trigger', 'to' => 'step_1']],
        ]])->assertOk()->json('result');

        $this->assertFalse($result['isError']);
        $saved = json_decode($result['content'][0]['text'], true);
        $this->assertSame(url('/workflows/'.$saved['id']), $saved['url']);
        $this->assertSame('From Claude Code', Workflow::query()->sole()->name);

        $this->rpc($token, 'tools/call', ['name' => 'delete_workflow', 'arguments' => ['id' => $saved['id']]])->assertJsonPath('result.isError', false);
        $this->assertSame(0, Workflow::query()->count());
        $this->rpc($token, 'nope/method')->assertJsonPath('error.code', -32601);
    }

    public function test_tokens_carry_their_users_permissions_and_are_required(): void
    {
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();

        [, $token] = ApiToken::issue(User::factory()->member(['workflows.view'])->create(), 'Cursor');
        $this->rpc($token, 'ping')->assertOk()->assertJsonPath('result', []);

        $result = $this->rpc($token, 'tools/call', ['name' => 'save_workflow', 'arguments' => ['name' => 'x', 'nodes' => [], 'edges' => []]])->json('result');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Create & edit workflows', $result['content'][0]['text']);
    }

    public function test_users_manage_their_own_tokens_and_only_the_hash_is_stored(): void
    {
        $user = User::factory()->member(['workflows.view'])->create();
        $this->actingAs($user);

        $plain = $this->postJson('/settings/tokens', ['name' => 'Claude Code'])->assertOk()->json('token');
        $this->assertStringStartsWith('ff_', $plain);
        $stored = ApiToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $stored->token_hash);

        $other = User::factory()->create();
        [$othersToken] = ApiToken::issue($other, 'Not yours');
        $this->deleteJson("/settings/tokens/{$othersToken->id}")->assertOk();
        $this->assertNotNull($othersToken->fresh(), 'cannot revoke someone else\'s token');

        $this->deleteJson("/settings/tokens/{$stored->id}")->assertOk()->assertJsonPath('tokens', []);
    }
}
