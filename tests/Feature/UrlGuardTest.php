<?php

namespace Tests\Feature;

use App\Domain\Engine\GraphRunner;
use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\Integrations\UrlGuard;
use App\Domain\Voice\Jobs\SendVoiceWebhook;
use App\Domain\Voice\VoiceService;
use App\Domain\Voice\VoiceWebhooks;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Models\User;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        UrlGuard::resolveUsing(fn (string $host) => match ($host) {
            'intranet.test' => ['10.1.2.3'],
            'metadata.google.internal' => ['169.254.169.254'],
            'mixed.test' => ['93.184.215.14', 'fd12::1'],
            'nowhere.test' => [],
            default => ['93.184.215.14'],
        });
    }

    private function blocked(string $url): string
    {
        try {
            UrlGuard::check($url);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $this->fail("{$url} should be blocked.");
    }

    public function test_private_internal_and_metadata_addresses_are_blocked(): void
    {
        foreach (['http://127.0.0.1/', 'http://10.0.0.5/', 'https://192.168.1.1:8443/x', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://intranet.test/', 'http://mixed.test/', 'http://100.64.0.1/'] as $url) {
            $this->assertStringContainsString('FLOWFORGE_ALLOW_PRIVATE_NETWORK=true', $this->blocked($url), $url);
        }
        $this->assertStringContainsString('(intranet.test is 10.1.2.3)', $this->blocked('http://intranet.test/'));
        $this->assertStringContainsString('(127.0.0.1)', $this->blocked('http://127.0.0.1:8080/admin?token=secret'));
        $this->assertStringNotContainsString('secret', $this->blocked('http://user:secret@127.0.0.1/?key=secret'));

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://metadata.google.internal/', 'http://[fd00:ec2::254]/', 'http://100.100.100.200/', 'http://0.0.0.0/', 'http://[fe80::1]/'] as $url) {
            $this->assertStringContainsString('always blocked', $this->blocked($url), $url);
        }
        $this->assertStringContainsString("Couldn't find the host nowhere.test", $this->blocked('https://nowhere.test/'));
        $this->assertStringContainsString('Only http:// and https://', $this->blocked('file:///etc/passwd'));

        $this->assertSame('93.184.215.14', UrlGuard::check('https://api.example.com/v1'));
    }

    public function test_the_switch_allows_private_networks_but_never_metadata(): void
    {
        config(['flowforge.allow_private_network' => true]);

        $this->assertSame('10.0.0.5', UrlGuard::check('http://10.0.0.5/'));
        $this->assertSame('127.0.0.1', UrlGuard::check('http://[::ffff:127.0.0.1]:11434/'));
        $this->assertSame('10.1.2.3', UrlGuard::check('http://intranet.test/'));
        $this->assertStringContainsString('always blocked', $this->blocked('http://169.254.169.254/'));
        $this->assertStringContainsString('always blocked', $this->blocked('http://metadata.google.internal/'));
    }

    public function test_requests_are_pinned_and_redirects_to_internal_addresses_are_refused(): void
    {
        $options = UrlGuard::protect(Http::timeout(7)->withOptions(['query' => ['a' => 1]]), 'https://api.example.com/x')->getOptions();
        $this->assertSame(['api.example.com:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame([7, ['a' => 1], 5], [$options['timeout'], $options['query'], $options['allow_redirects']['max']]);

        $onRedirect = $options['allow_redirects']['on_redirect'];
        $onRedirect(null, null, new Uri('https://other.example.com/next')); // a public hop is fine
        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data/', 'http://intranet.test/'] as $target) {
            try {
                $onRedirect(null, null, new Uri($target));
                $this->fail("A redirect to {$target} must be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('FlowForge', $e->getMessage());
            }
        }
    }

    public function test_an_http_request_step_to_a_private_address_fails_with_a_clear_message(): void
    {
        Http::fake();
        $workflow = Workflow::query()->create(['name' => 'Probe', 'is_active' => true, 'graph' => GraphValidator::normalize(['nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'webhook', 'trigger' => 'catch', 'config' => []]],
            ['id' => 'step_1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 0], 'data' => ['integration' => 'http', 'action' => 'request', 'label' => 'Probe', 'config' => ['method' => 'GET', 'url' => 'http://127.0.0.1:6379/']]],
        ], 'edges' => [['source' => 'trigger', 'target' => 'step_1']]])]);
        $execution = $workflow->executions()->create(['status' => WorkflowExecution::PENDING, 'mode' => 'webhook', 'trigger_payload' => []]);

        (new RunWorkflow($execution->id))->handle(app(GraphRunner::class));

        $step = collect($execution->refresh()->steps)->firstWhere('node_id', 'step_1');
        $this->assertSame(['failed', 'failed'], [$execution->status, $step['status']]);
        $this->assertStringContainsString("FlowForge doesn't send requests to private or internal network addresses (127.0.0.1)", $step['error']);
        $this->assertSame('http://127.0.0.1:6379/', $step['request']['url']);
        Http::assertNothingSent();
    }

    public function test_voice_webhooks_and_openapi_imports_refuse_private_urls(): void
    {
        Http::fake();
        $agent = app(VoiceService::class)->create('Front desk', 'clinic-receptionist', ['business_name' => 'Smile Dental']);

        // Logged as a failed delivery and not retried (no exception for the queue).
        (new SendVoiceWebhook($agent->id, 'http://192.168.1.20/hook', 'call_ended', ['call' => ['id' => 1]]))->handle(app(VoiceWebhooks::class));
        $this->assertStringContainsString('private or internal network', app(VoiceWebhooks::class)->recent($agent->id)[0]['error']);
        $this->assertNull(app(VoiceWebhooks::class)->recent($agent->id)[0]['status']);

        $this->post('/apps/custom', ['source' => 'http://10.0.0.5/openapi.json'])->assertSessionHasErrors(['source' => "Couldn't download the OpenAPI document: FlowForge doesn't send requests to private or internal network addresses (10.0.0.5). If this is your own server, set FLOWFORGE_ALLOW_PRIVATE_NETWORK=true in .env."]);
        Http::assertNothingSent();
    }
}
