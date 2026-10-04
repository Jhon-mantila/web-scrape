<?php

namespace App\ProcessScraping\Ai;

use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Ai\Support\AiHttpErrorMapper;
use App\ProcessScraping\Ai\Support\AiSettings;
use Illuminate\Support\Facades\Http;

abstract class OpenAiCompatibleChatClient implements TextGenerationClient
{
    abstract protected function providerKey(): string;

    abstract protected function baseUrl(): string;

    abstract protected function apiKey(): string;

    public function generate(string $system, string $prompt, ?string $model = null): string
    {
        $model ??= AiSettings::defaultModel();
        $url = rtrim($this->baseUrl(), '/').'/chat/completions';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => AiSettings::temperature(),
            'max_tokens' => AiSettings::maxTokens(),
            'stream' => false,
        ];

        if (AiSettings::formatJson()) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $key = $this->apiKey();

        if ($key === '') {
            throw AiHttpErrorMapper::fromResponse(
                $this->providerKey(),
                401,
                '{"error":"missing_api_key"}',
                $url,
            );
        }

        try {
            $response = Http::timeout(AiSettings::timeout())
                ->connectTimeout(15)
                ->acceptJson()
                ->withToken($key)
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw AiHttpErrorMapper::connection($this->providerKey(), $url, $e->getMessage());
        }

        if ($response->failed()) {
            throw AiHttpErrorMapper::fromResponse(
                $this->providerKey(),
                $response->status(),
                $response->body(),
                $url,
            );
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    public function unloadModels(?string $model = null): void
    {
        // APIs remotas no cargan modelos en RAM local.
    }

    public function supportsModelUnload(): bool
    {
        return false;
    }
}
