<?php

namespace Tests\Feature;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\CredentialVault;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredentialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_credentials_token_is_fetched_once_cached_and_sent_as_bearer(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21-token', 'expires_in' => 32400, 'token_type' => 'Bearer']),
            'api-m.sandbox.paypal.com/*' => Http::response(['id' => 'ORDER-1', 'status' => 'CREATED']),
        ]);
        $credential = Credential::query()->create(['name' => 'PayPal', 'integration' => 'paypal', 'auth_type' => 'oauth2_client_credentials',
            'data' => ['environment' => 'api-m.sandbox.paypal.com', 'client_id' => 'cid', 'client_secret' => 'secret']]);

        $executor = app(WorkflowActionExecutor::class);
        foreach ([1, 2] as $run) {
            $result = $executor->execute(['integration' => 'paypal', 'action' => 'get_order', 'credential_id' => $credential->id, 'config' => ['order_id' => 'ORDER-1']], ['steps' => []]);
            $this->assertSame('CREATED', $result->output['status']);
        }

        Http::assertSentCount(3); // one token request + two API calls
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/oauth2/token')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:secret')) && $r['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/checkout/orders/ORDER-1') && $r->hasHeader('Authorization', 'Bearer A21-token'));
    }

    public function test_zoom_server_to_server_overrides_grant_type_with_credential_values(): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => 'zoom-token', 'expires_in' => 3600]),
            'api.zoom.us/*' => Http::response(['meetings' => []]),
        ]);
        $credential = Credential::query()->create(['name' => 'Zoom', 'integration' => 'zoom', 'auth_type' => 'oauth2_client_credentials',
            'data' => ['account_id' => 'ACC123', 'client_id' => 'cid', 'client_secret' => 'sec']]);

        app(WorkflowActionExecutor::class)->execute(['integration' => 'zoom', 'action' => 'list_meetings', 'credential_id' => $credential->id, 'config' => []], ['steps' => []]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'zoom.us/oauth/token') && $r['grant_type'] === 'account_credentials' && $r['account_id'] === 'ACC123');
    }

    public function test_pkce_connect_sends_a_challenge_and_the_callback_sends_the_verifier(): void
    {
        $this->actingAs(User::factory()->create());
        $credential = Credential::query()->create(['name' => 'X', 'integration' => 'x_twitter', 'auth_type' => 'oauth2', 'data' => ['client_id' => 'cid', 'client_secret' => 'sec']]);

        $redirect = $this->get("/credentials/{$credential->id}/connect")->assertRedirect()->headers->get('Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertNotEmpty($params['code_challenge']);

        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'x-token', 'refresh_token' => 'r', 'expires_in' => 7200])]);
        $this->get('/oauth/callback?'.http_build_query(['state' => $params['state'], 'code' => 'abc']))->assertRedirect('/credentials');

        Http::assertSent(function ($r) use ($params) {
            $verifier = $r['code_verifier'] ?? '';

            return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') === $params['code_challenge']
                && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:sec'));
        });
        $this->assertSame('x-token', $credential->fresh()->data['access_token']);
    }

    public function test_linkedin_posts_as_the_connected_member_and_formats_little_text(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'aB3xYz', 'name' => 'Zara Khan']),
            'api.linkedin.com/rest/posts' => Http::response([], 201),
        ]);
        $credential = Credential::query()->create(['name' => 'LinkedIn', 'integration' => 'linkedin', 'auth_type' => 'oauth2',
            'data' => ['client_id' => 'cid', 'client_secret' => 'sec', 'access_token' => 'tok', 'api_version' => '202609']]);
        $node = ['integration' => 'linkedin', 'action' => 'create_post', 'credential_id' => $credential->id,
            'config' => ['commentary' => "Lesson (one) #Growth\n\nWhat's yours?", 'visibility' => 'PUBLIC']];

        $executor = app(WorkflowActionExecutor::class);
        $executor->execute($node, ['steps' => []]);
        $executor->execute($node, ['steps' => []]);

        Http::assertSentCount(3); // profile looked up once, then cached on the credential
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/posts') && $r['author'] === 'urn:li:person:aB3xYz'
            && $r['commentary'] === "Lesson \\(one\\) {hashtag|\\#|Growth}\n\nWhat's yours?" && $r->hasHeader('LinkedIn-Version', '202609'));
        $this->assertSame('Zara Khan', $credential->fresh()->data['account_name']);
    }

    public function test_instagram_uses_the_business_account_linked_to_the_page(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['name' => 'Cafe', 'instagram_business_account' => ['id' => '17841400000000001']]]]),
            'graph.facebook.com/*' => Http::response(['data' => []]),
        ]);
        $credential = Credential::query()->create(['name' => 'IG', 'integration' => 'instagram', 'auth_type' => 'bearer', 'data' => ['token' => 'tok']]);

        app(WorkflowActionExecutor::class)->execute(['integration' => 'instagram', 'action' => 'list_media', 'credential_id' => $credential->id, 'config' => []], ['steps' => []]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/17841400000000001/media'));
    }

    public function test_http_errors_carry_the_manifest_hint(): void
    {
        Http::fake(['api.linkedin.com/*' => Http::response(['message' => '', 'status' => 403], 403)]);
        $credential = Credential::query()->create(['name' => 'LinkedIn', 'integration' => 'linkedin', 'auth_type' => 'bearer',
            'data' => ['token' => 'tok', 'api_version' => '202609', 'person_urn' => 'urn:li:person:x']]);

        try {
            app(WorkflowActionExecutor::class)->execute(['integration' => 'linkedin', 'action' => 'create_post', 'credential_id' => $credential->id,
                'config' => ['commentary' => 'Hi']], ['steps' => []]);
            $this->fail('Expected a 403');
        } catch (ActionException $e) {
            $this->assertStringContainsString('Leave "Post as" empty', $e->getMessage());
        }
    }

    public function test_token_can_be_sent_as_a_query_parameter(): void
    {
        $vault = app(CredentialVault::class);
        $credential = new Credential(['auth_type' => 'bearer']);
        $credential->data = ['token' => 'tkn'];
        $query = [];

        $request = $vault->authorize(Http::withOptions([]), ['type' => 'bearer', 'token_query' => 'access_token'], $credential, $query);

        $this->assertSame(['access_token' => 'tkn'], $query);
        $this->assertArrayNotHasKey('Authorization', $request->getOptions()['headers'] ?? []);
    }
}
