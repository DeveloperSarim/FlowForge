<?php

namespace App\Domain\Assistant;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIStatusException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * One tool-calling turn against any supported provider. History is kept in each provider's native
 * format (so assistant turns, tool calls and Claude's thinking blocks go back exactly as received);
 * this class only normalises what the agent needs: text, tool calls and token usage.
 *
 * Token economy:
 *  - the system prompt and tool list are static, so they hit each provider's prompt cache
 *    (explicit cache_control for Claude; automatic prefix caching for OpenAI / DeepSeek / Qwen / Gemini)
 *  - output is capped and reasoning effort is configurable (Claude effort, OpenAI reasoning_effort)
 */
class LlmClient
{
    /** Models served with Anthropic's server-side refusal fallback ("default" routing). */
    private const CLAUDE_FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    /** Seam for tests: inject a PSR-18 client to fake Anthropic responses. */
    public ?ClientInterface $anthropicTransport = null;

    public function protocol(array $config): string
    {
        return Providers::protocol($config);
    }

    public function ping(array $config): string
    {
        $history = [];
        $this->addUser($config, $history, 'Reply with the single word OK.');

        return $this->turn($config, 'You are a connectivity check. Answer in one word.', [], $history, 1024)->text;
    }

    public function addUser(array $config, array &$history, string $text): void
    {
        $history[] = $this->protocol($config) === 'gemini'
            ? ['role' => 'user', 'parts' => [['text' => $text]]]
            : ['role' => 'user', 'content' => $text];
    }

    /** @param list<array{id: string, name: string, content: string, is_error?: bool}> $results */
    public function addToolResults(array $config, array &$history, array $results): void
    {
        switch ($this->protocol($config)) {
            case 'anthropic':
                $history[] = ['role' => 'user', 'content' => array_map(fn ($r) => array_filter([
                    'type' => 'tool_result', 'tool_use_id' => $r['id'], 'content' => $r['content'], 'is_error' => ! empty($r['is_error']) ?: null,
                ], fn ($v) => $v !== null), $results)];
                break;
            case 'gemini':
                $history[] = ['role' => 'user', 'parts' => array_map(fn ($r) => ['functionResponse' => array_filter([
                    'id' => str_contains($r['id'], '#') ? null : $r['id'], 'name' => $r['name'], 'response' => ['result' => $r['content']],
                ])], $results)];
                break;
            default:
                foreach ($results as $r) {
                    $history[] = ['role' => 'tool', 'tool_call_id' => $r['id'], 'content' => $r['content']];
                }
        }
    }

    /**
     * @param  list<array{name: string, description: string, parameters: array}>  $tools
     */
    public function turn(array $config, string $system, array $tools, array &$history, ?int $maxTokens = null): LlmTurn
    {
        return match ($this->protocol($config)) {
            'anthropic' => $this->anthropic($config, $system, $tools, $history, $maxTokens ?? 16000),
            'gemini' => $this->gemini($config, $system, $tools, $history, $maxTokens ?? 8192),
            default => $this->openAiCompatible($config, $system, $tools, $history, $maxTokens ?? 8000),
        };
    }

    private function anthropic(array $config, string $system, array $tools, array &$history, int $maxTokens): LlmTurn
    {
        $model = $config['model'];
        $base = rtrim($config['base_url'] ?? '', '/');
        $client = new AnthropicClient(
            apiKey: $config['api_key'],
            baseUrl: $base !== '' ? $base : null,
            requestOptions: $this->anthropicTransport ? ['transporter' => $this->anthropicTransport] : null,
        );

        $params = [
            'maxTokens' => $maxTokens,
            'model' => $model,
            'messages' => $history,
            'system' => [['type' => 'text', 'text' => $system]],
            // Top-level cache_control caches the growing prefix (system + tools + history) between agent steps.
            'cacheControl' => ['type' => 'ephemeral'],
        ];
        if ($tools) {
            $params['tools'] = array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['parameters']], $tools);
        }
        if (preg_match('/^claude-(opus|sonnet|haiku|fable)-5/', $model) && ($config['effort'] ?? 'medium') !== 'none') {
            $params['outputConfig'] = ['effort' => $config['effort'] ?? 'medium'];
        }
        if (in_array($model, self::CLAUDE_FALLBACK_MODELS, true)) {
            $params['betas'] = ['server-side-fallback-2026-07-01'];
            $params['fallbacks'] = 'default';
        }

        try {
            $response = $client->beta->messages->create(...$params);
        } catch (APIStatusException $e) {
            throw new RuntimeException('Claude API error: '.$e->getMessage(), previous: $e);
        }

        $content = json_decode(json_encode($response->content), true) ?? [];
        $history[] = ['role' => 'assistant', 'content' => $content];

        $turn = new LlmTurn;
        $turn->stop = $response->stopReason;
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $turn->text .= $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $turn->toolCalls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => (array) ($block['input'] ?? [])];
            }
        }
        if ($turn->stop === 'refusal') {
            $turn->text = $turn->text ?: 'The model declined this request.';
        }

        $usage = $response->usage;
        $turn->usage = [
            'input' => (int) $usage->inputTokens + (int) ($usage->cacheCreationInputTokens ?? 0) + (int) ($usage->cacheReadInputTokens ?? 0),
            'cached' => (int) ($usage->cacheReadInputTokens ?? 0),
            'output' => (int) $usage->outputTokens,
        ];

        return $turn;
    }

    private function openAiCompatible(array $config, string $system, array $tools, array &$history, int $maxTokens): LlmTurn
    {
        $model = $config['model'];
        $body = ['model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ...$history]];
        if ($tools) {
            $body['tools'] = array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']]], $tools);
            $body['tool_choice'] = 'auto';
        }

        if ($config['provider'] === 'openai') {
            $body['max_completion_tokens'] = $maxTokens;
            if (preg_match('/^(gpt-5|o\d)/', $model)) {
                $body['reasoning_effort'] = $config['effort'] ?? 'low';
            }
        } else {
            $body['max_tokens'] = min($maxTokens, 8192);
            // A custom endpoint only gets reasoning_effort when the admin chose one (unknown fields upset some servers).
            if ($config['provider'] === 'custom' && in_array($config['effort'] ?? null, ['low', 'medium', 'high'], true)) {
                $body['reasoning_effort'] = $config['effort'];
            }
        }

        $request = Http::acceptJson()->timeout(240);
        if (! empty($config['api_key'])) {
            $request = $request->withToken($config['api_key']); // local servers (Ollama, LM Studio) need none
        }
        $response = $request->post(rtrim($config['base_url'], '/').'/chat/completions', $body);

        if ($response->failed()) {
            throw new RuntimeException(Providers::label($config).' error: '.($response->json('error.message') ?? $response->json('error') ?? mb_strimwidth($response->body(), 0, 300, '…')));
        }

        $message = (array) $response->json('choices.0.message');
        // Keep only what the API accepts back (DeepSeek rejects its own reasoning_content in history).
        $history[] = array_filter([
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
        ], fn ($v) => $v !== null) + ['content' => null];

        $turn = new LlmTurn;
        $turn->stop = $response->json('choices.0.finish_reason');
        $turn->text = (string) ($message['content'] ?? '');
        foreach ($message['tool_calls'] ?? [] as $call) {
            $turn->toolCalls[] = [
                'id' => $call['id'],
                'name' => $call['function']['name'],
                'input' => json_decode($call['function']['arguments'] ?? '{}', true) ?: [],
            ];
        }
        $turn->usage = [
            'input' => (int) $response->json('usage.prompt_tokens', 0),
            'cached' => (int) ($response->json('usage.prompt_tokens_details.cached_tokens') ?? $response->json('usage.prompt_cache_hit_tokens') ?? 0),
            'output' => (int) $response->json('usage.completion_tokens', 0),
        ];

        return $turn;
    }

    private function gemini(array $config, string $system, array $tools, array &$history, int $maxTokens): LlmTurn
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $history,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if ($tools) {
            $body['tools'] = [['functionDeclarations' => array_map(fn ($t) => array_filter([
                'name' => $t['name'],
                'description' => $t['description'],
                // Gemini wants no "parameters" at all for argument-less functions.
                'parameters' => empty((array) ($t['parameters']['properties'] ?? [])) ? null : $this->geminiSchema($t['parameters']),
            ]), $tools)]];
        }

        $response = Http::withHeaders(['x-goog-api-key' => $config['api_key']])->acceptJson()->timeout(240)
            ->post(rtrim($config['base_url'], '/').'/models/'.rawurlencode($config['model']).':generateContent', $body);

        if ($response->failed()) {
            throw new RuntimeException('Gemini error: '.($response->json('error.message') ?? mb_strimwidth($response->body(), 0, 300, '…')));
        }

        $content = (array) ($response->json('candidates.0.content') ?? []);
        $content['role'] = 'model';
        $content['parts'] ??= [];
        $history[] = $content; // unchanged, so thought signatures go back as received

        $turn = new LlmTurn;
        $turn->stop = $response->json('candidates.0.finishReason');
        foreach ($content['parts'] as $i => $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $turn->text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $turn->toolCalls[] = [
                    'id' => $part['functionCall']['id'] ?? $part['functionCall']['name'].'#'.$i,
                    'name' => $part['functionCall']['name'],
                    'input' => (array) ($part['functionCall']['args'] ?? []),
                ];
            }
        }
        $turn->usage = [
            'input' => (int) $response->json('usageMetadata.promptTokenCount', 0),
            'cached' => (int) $response->json('usageMetadata.cachedContentTokenCount', 0),
            'output' => (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
        ];

        return $turn;
    }

    /** Gemini accepts an OpenAPI subset: free-form objects become JSON strings. */
    private function geminiSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        if (($schema['type'] ?? null) === 'object' && empty($schema['properties'])) {
            return ['type' => 'string', 'description' => trim(($schema['description'] ?? '').' (a JSON-encoded object)')];
        }
        foreach ($schema['properties'] ?? [] as $key => $property) {
            $schema['properties'][$key] = $this->geminiSchema($property);
        }
        if (isset($schema['items'])) {
            $schema['items'] = $this->geminiSchema($schema['items']);
        }

        return $schema;
    }
}
