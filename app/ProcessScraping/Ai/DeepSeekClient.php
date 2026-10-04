<?php

namespace App\ProcessScraping\Ai;

class DeepSeekClient extends OpenAiCompatibleChatClient
{
    protected function providerKey(): string
    {
        return 'deepseek';
    }

    protected function baseUrl(): string
    {
        return (string) config('services.deepseek.base_url', 'https://api.deepseek.com');
    }

    protected function apiKey(): string
    {
        return (string) config('services.deepseek.api_key', '');
    }
}
