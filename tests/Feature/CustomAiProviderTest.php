<?php

namespace Tests\Feature;

use App\Domain\Assistant\Controllers\AssistantController;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomAiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->owner()->create());
    }

    private function reply(): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]]];
    }

    public function test_a_custom_openai_compatible_endpoint_works_without_a_key(): void
    {
        Http::fake(['gateway.test/v1/chat/completions' => Http::response($this->reply())]);
        $custom = ['provider' => 'custom', 'label' => 'My gateway', 'format' => 'openai', 'base_url' => 'https://gateway.test/v1', 'model' => 'llama-3.3-70b'];

        $this->postJson('/settings/ai/test', $custom)->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn ($r) => $r->url() === 'https://gateway.test/v1/chat/completions' && ! $r->hasHeader('Authorization')
            && $r['model'] === 'llama-3.3-70b' && ! isset($r['reasoning_effort']));

        $this->put('/settings/ai', $custom)->assertSessionHasNoErrors();
        $saved = app(SystemSettings::class)->get('ai');
        $this->assertSame(['custom', 'openai', 'none', 'My gateway'], [$saved['provider'], $saved['format'], $saved['effort'], $saved['label']]);
        $this->assertSame('My gateway', AssistantController::status(app(SystemSettings::class), app(ManifestLoader::class))['provider']);
    }

    public function test_thinking_effort_and_a_key_are_sent_only_when_given(): void
    {
        Http::fake(['openrouter.test/api/v1/chat/completions' => Http::response($this->reply())]);

        $this->postJson('/settings/ai/test', ['provider' => 'custom', 'base_url' => 'https://openrouter.test/api/v1', 'model' => 'deepseek/deepseek-r1',
            'api_key' => 'sk-or-123', 'effort' => 'high'])->assertOk();
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-or-123') && $r['reasoning_effort'] === 'high');
    }

    public function test_custom_providers_need_a_base_url_and_anthropic_format_needs_a_key(): void
    {
        $this->put('/settings/ai', ['provider' => 'custom', 'model' => 'x'])->assertSessionHasErrors('base_url');
        $this->put('/settings/ai', ['provider' => 'custom', 'format' => 'anthropic', 'base_url' => 'https://claude-gateway.test', 'model' => 'claude-sonnet-5-5'])
            ->assertSessionHasErrors('api_key');
        $this->put('/settings/ai', ['provider' => 'custom', 'format' => 'grpc', 'base_url' => 'https://x.test', 'model' => 'x'])->assertSessionHasErrors('format');

        // Back on a built-in provider, "don't send" isn't an option: it becomes medium, and custom-only fields are dropped.
        $this->put('/settings/ai', ['provider' => 'deepseek', 'api_key' => 'sk-1', 'model' => 'deepseek-chat', 'effort' => 'none', 'label' => 'x', 'format' => 'anthropic'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $saved = app(SystemSettings::class)->get('ai');
        $this->assertSame(['medium', 'https://api.deepseek.com'], [$saved['effort'], $saved['base_url']]);
        $this->assertArrayNotHasKey('label', $saved);
    }
}
