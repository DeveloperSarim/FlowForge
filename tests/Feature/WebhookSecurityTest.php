<?php

namespace Tests\Feature;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '{"order":{"id":1001,"total":"49.00"}}';

    private function hook(array $config = [], bool $active = true, string $app = 'webhook', string $trigger = 'catch'): Workflow
    {
        return Workflow::query()->create(['name' => 'Hook', 'is_active' => $active, 'graph' => ['nodes' => [[
            'id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => $app, 'trigger' => $trigger, 'config' => $config],
        ]], 'edges' => []]]);
    }

    private function send(Workflow $workflow, array $headers = [], string $body = self::BODY, string $query = ''): TestResponse
    {
        return $this->call('POST', "/api/webhook/{$workflow->webhook_token}{$query}", [], [], [],
            $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $body);
    }

    public function test_credentials_in_headers_and_the_query_string_are_stored_redacted(): void
    {
        $workflow = $this->hook(active: false);
        $headers = ['Authorization' => 'Bearer live-token-123', 'X-Api-Key' => 'key-456', 'X-Shopify-Hmac-Sha256' => 'c2lnbmF0dXJl', 'Cookie' => 'session=abc', 'X-Shop-Domain' => 'demo.myshopify.com'];
        $query = '?token=query-secret&api_key=k2&page=2';

        $this->send($workflow, $headers, self::BODY, $query)->assertOk()->assertJsonPath('status', 'captured');
        $sample = $workflow->fresh()->trigger_state['sample'];

        $workflow->update(['is_active' => true]);
        $this->send($workflow, $headers, self::BODY, $query)->assertOk()->assertJsonPath('status', 'queued');
        $execution = WorkflowExecution::query()->sole();
        $output = collect($execution->steps)->firstWhere('type', 'trigger')['output'];

        foreach ([$sample, $execution->trigger_payload, $output] as $stored) {
            foreach (['authorization', 'x-api-key', 'x-shopify-hmac-sha256', 'cookie'] as $header) {
                $this->assertSame('[redacted]', $stored['headers'][$header]);
            }
            $this->assertSame('demo.myshopify.com', $stored['headers']['x-shop-domain']);
            $this->assertSame(['token' => '[redacted]', 'api_key' => '[redacted]', 'page' => '2'], $stored['query']);
            $this->assertSame(['order' => ['id' => 1001, 'total' => '49.00']], $stored['data']); // the body is left as sent
        }
        $this->assertStringNotContainsString('live-token-123', json_encode([$execution->toArray(), $workflow->fresh()->trigger_state]));
    }

    public function test_oversized_bodies_are_refused_with_413(): void
    {
        config(['flowforge.webhook_max_kb' => 1]);
        $workflow = $this->hook();
        $agent = VoiceAgent::query()->create(['name' => 'Desk', 'provider' => 'vapi', 'config' => []]);
        $big = json_encode(['note' => str_repeat('x', 2000)]);

        $this->send($workflow, [], $big)->assertStatus(413)->assertJsonPath('message', fn ($m) => str_contains($m, 'too large'));
        $this->send($workflow, ['Content-Length' => '999999'], '{}')->assertStatus(413); // the declared size is checked first
        $this->call('POST', "/api/voice/{$agent->token}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $big)->assertStatus(413);
        $this->send($workflow, [], '{"small":true}')->assertOk();
        $this->assertSame(1, WorkflowExecution::query()->count());
    }

    public function test_each_check_accepts_a_genuine_request_and_refuses_a_wrong_one(): void
    {
        $now = (string) now()->getTimestamp();
        $hmac = fn (string $data, string $secret = 's3cret', bool $raw = false) => hash_hmac('sha256', $data, $secret, $raw);
        $cases = [
            'header' => [['verify' => 'header'], ['X-Webhook-Secret' => 's3cret'], ['X-Webhook-Secret' => 'guess']],
            'basic' => [['verify' => 'basic', 'username' => 'shop'], ['Authorization' => 'Basic '.base64_encode('shop:s3cret')], ['Authorization' => 'Basic '.base64_encode('shop:nope')]],
            'github' => [['verify' => 'hmac'], ['X-Hub-Signature-256' => 'sha256='.$hmac(self::BODY)], ['X-Hub-Signature-256' => 'sha256='.$hmac(self::BODY, 'other')]],
            'shopify' => [['verify' => 'hmac', 'signature_header' => 'X-Shopify-Hmac-Sha256', 'encoding' => 'base64'],
                ['X-Shopify-Hmac-Sha256' => base64_encode($hmac(self::BODY, 's3cret', true))], ['X-Shopify-Hmac-Sha256' => base64_encode($hmac('{}', 's3cret', true))]],
            'stripe' => [['verify' => 'stripe'], ['Stripe-Signature' => "t={$now},v1=".$hmac("{$now}.".self::BODY)], ['Stripe-Signature' => "t={$now},v1=".$hmac(self::BODY)]],
            'slack' => [['verify' => 'slack'], ['X-Slack-Request-Timestamp' => $now, 'X-Slack-Signature' => 'v0='.$hmac("v0:{$now}:".self::BODY)],
                ['X-Slack-Request-Timestamp' => $now, 'X-Slack-Signature' => 'v0='.$hmac("v0:{$now}:{}")]],
        ];

        foreach ($cases as $name => [$config, $good, $bad]) {
            $workflow = $this->hook($config + ['signing_secret' => 's3cret']);
            $this->send($workflow, $good)->assertOk()->assertJsonPath('status', 'queued');
            $this->send($workflow, $bad)->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'could not be verified'));
            $this->send($workflow)->assertStatus(401);
            $this->assertSame(1, $workflow->executions()->count(), "{$name}: only the genuine request starts a run");
        }

        // A check without a secret refuses everything rather than letting requests through.
        $this->send($this->hook(['verify' => 'header']), ['X-Webhook-Secret' => ''])->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'no secret'));
    }

    public function test_stripe_and_slack_refuse_signatures_older_than_five_minutes(): void
    {
        $old = (string) now()->subSeconds(301)->getTimestamp();

        $stripe = $this->hook(['verify' => 'stripe', 'signing_secret' => 'whsec_test']);
        $this->send($stripe, ['Stripe-Signature' => "t={$old},v1=".hash_hmac('sha256', "{$old}.".self::BODY, 'whsec_test')])
            ->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'more than 5 minutes old'));

        $slack = $this->hook(['verify' => 'slack', 'signing_secret' => 'slack-secret']);
        $this->send($slack, ['X-Slack-Request-Timestamp' => $old, 'X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$old}:".self::BODY, 'slack-secret')])
            ->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'more than 5 minutes old'));

        $this->assertSame(0, WorkflowExecution::query()->count());
    }

    public function test_inactive_workflows_check_requests_before_saving_the_sample(): void
    {
        $workflow = $this->hook(['verify' => 'header', 'secret_header' => 'X-Token', 'signing_secret' => 's3cret'], active: false);

        $this->send($workflow, ['X-Token' => 's3cret'])->assertOk()->assertJsonPath('status', 'captured');
        $this->send($workflow, ['X-Token' => 'nope'], '{"evil":true}')->assertStatus(401);

        $this->assertSame(['order' => ['id' => 1001, 'total' => '49.00']], $workflow->fresh()->trigger_state['sample']['data']);
    }

    public function test_app_triggers_check_their_signature_once_the_secret_is_pasted(): void
    {
        $now = (string) now()->getTimestamp();
        $signed = ['Stripe-Signature' => "t={$now},v1=".hash_hmac('sha256', "{$now}.".self::BODY, 'whsec_test')];

        $this->send($this->hook([], true, 'stripe', 'event'))->assertOk(); // no secret yet: not checked
        $stripe = $this->hook(['signing_secret' => 'whsec_test'], true, 'stripe', 'event');
        $this->send($stripe)->assertStatus(401);
        $this->send($stripe, $signed)->assertOk();

        $shopify = $this->hook(['signing_secret' => 'shpss'], true, 'shopify', 'order_created');
        $this->send($shopify, ['X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', self::BODY, 'shpss', true))])->assertOk();
        $this->send($shopify, ['X-Shopify-Hmac-Sha256' => 'bm9wZQ=='])->assertStatus(401);

        // WooCommerce's unsigned ping when a webhook is created is answered without starting a run.
        $woo = $this->hook(['signing_secret' => 'wc'], true, 'woocommerce', 'webhook_event');
        $this->call('POST', "/api/webhook/{$woo->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'webhook_id=12')->assertOk();
        $this->assertSame(0, $woo->executions()->count());
    }

    public function test_the_migration_redacts_samples_and_runs_stored_before(): void
    {
        $raw = ['data' => ['a' => 1], 'query' => ['token' => 'q1', 'page' => '1'], 'headers' => ['authorization' => 'Bearer old', 'x-github-event' => 'push'], 'method' => 'POST'];
        $workflow = $this->hook(active: false);
        $workflow->mergeTriggerState(['sample' => $raw, 'sample_at' => '2026-01-01T00:00:00+00:00']);
        $id = DB::table('workflow_executions')->insertGetId(['workflow_id' => $workflow->id, 'status' => 'completed', 'mode' => 'webhook', 'trigger_payload' => json_encode($raw),
            'steps' => json_encode([['node_id' => 'trigger', 'type' => 'trigger', 'status' => 'success', 'output' => $raw]]), 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_01_08_000002_harden_webhooks.php');
        $migration->down();
        $migration->up();

        $expected = ['data' => ['a' => 1], 'query' => ['token' => '[redacted]', 'page' => '1'], 'headers' => ['authorization' => '[redacted]', 'x-github-event' => 'push'], 'method' => 'POST'];
        $execution = WorkflowExecution::query()->findOrFail($id);
        $this->assertSame($expected, $execution->trigger_payload);
        $this->assertSame($expected, $execution->steps[0]['output']);
        $this->assertSame($expected, $workflow->fresh()->trigger_state['sample']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $workflow->fresh()->trigger_state['sample_at']);
    }
}
