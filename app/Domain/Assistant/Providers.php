<?php

namespace App\Domain\Assistant;

/**
 * The AI providers the assistant can use. DeepSeek, Qwen and OpenAI speak the OpenAI chat-completions
 * protocol; Claude goes through the official Anthropic SDK; Gemini uses its native generateContent API.
 */
class Providers
{
    public const ALL = [
        'openai' => [
            'name' => 'OpenAI (ChatGPT)', 'protocol' => 'openai', 'icon_key' => 'openai',
            'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-5-mini',
            'models' => ['gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'gpt-4.1', 'gpt-4.1-mini'],
            'key_url' => 'https://platform.openai.com/api-keys',
        ],
        'anthropic' => [
            'name' => 'Anthropic Claude', 'protocol' => 'anthropic', 'icon_key' => 'anthropic',
            'base_url' => 'https://api.anthropic.com', 'model' => 'claude-opus-5-5',
            'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
            'key_url' => 'https://platform.claude.com/settings/keys',
        ],
        'gemini' => [
            'name' => 'Google Gemini', 'protocol' => 'gemini', 'icon_key' => 'google_gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.5-flash',
            'models' => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'],
            'key_url' => 'https://aistudio.google.com/apikey',
        ],
        'deepseek' => [
            'name' => 'DeepSeek', 'protocol' => 'openai', 'icon_key' => 'deepseek',
            'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-chat',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
            'key_url' => 'https://platform.deepseek.com/api_keys',
        ],
        'qwen' => [
            'name' => 'Qwen (Alibaba Cloud)', 'protocol' => 'openai', 'icon_key' => 'qwen',
            'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus',
            'models' => ['qwen-max', 'qwen-plus', 'qwen-turbo', 'qwen3-coder-plus'],
            'base_urls' => [
                'https://dashscope-intl.aliyuncs.com/compatible-mode/v1' => 'International (Singapore)',
                'https://dashscope-us.aliyuncs.com/compatible-mode/v1' => 'United States (Virginia)',
                'https://dashscope.aliyuncs.com/compatible-mode/v1' => 'China (Beijing)',
            ],
            'key_url' => 'https://modelstudio.console.alibabacloud.com/',
        ],
    ];

    public static function get(string $provider): array
    {
        return self::ALL[$provider] ?? throw new \InvalidArgumentException("Unknown AI provider [$provider].");
    }
}
