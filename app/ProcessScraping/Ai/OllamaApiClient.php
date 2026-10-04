<?php

namespace App\ProcessScraping\Ai;

class OllamaApiClient extends OpenAiCompatibleChatClient
{
    protected function providerKey(): string
    {
        return 'ollama_api';
    }

    protected function baseUrl(): string
    {
        return (string) config('services.ollama_api.base_url', 'https://ollama.com/api');
    }

    protected function apiKey(): string
    {
        return (string) config('services.ollama_api.api_key', '');
    }
}
