<?php

namespace App\ProcessScraping\Ai;

use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Ai\Support\AiHttpErrorMapper;
use App\ProcessScraping\Ai\Support\AiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OllamaClient implements TextGenerationClient
{
    public function generate(string $system, string $prompt, ?string $model = null): string
    {
        $url = rtrim(config('services.ollama.url'), '/').'/api/generate';
        $model ??= AiSettings::defaultModel();

        $payload = [
            'model' => $model,
            'system' => $system,
            'prompt' => $prompt,
            'stream' => false,
        ];

        if (AiSettings::formatJson()) {
            $payload['format'] = 'json';
        }

        if (str_contains($model, 'qwen3')) {
            $payload['think'] = false;
        }

        $options = array_filter([
            'temperature' => AiSettings::temperature(),
            'num_ctx' => (int) config('services.ollama.num_ctx'),
            'num_predict' => AiSettings::maxTokens(),
        ], fn ($value) => $value !== null && $value !== 0 && $value !== '');

        if ($options !== []) {
            $payload['options'] = $options;
        }

        try {
            $response = Http::timeout(AiSettings::timeout())
                ->connectTimeout(15)
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw AiHttpErrorMapper::connection('ollama_local', $url, $e->getMessage());
        }

        if ($response->failed()) {
            throw AiHttpErrorMapper::fromResponse(
                'ollama_local',
                $response->status(),
                $response->body(),
                $url,
            );
        }

        return (string) ($response->json('response') ?? '');
    }

    public function unloadModels(?string $model = null): void
    {
        $models = array_values(array_unique(array_filter([
            $model,
            config('services.ollama.model'),
            config('services.ollama.model_premium'),
        ])));

        foreach ($models as $name) {
            if ($name === null || $name === '') {
                continue;
            }

            try {
                Http::timeout(15)
                    ->acceptJson()
                    ->post(rtrim(config('services.ollama.url'), '/').'/api/generate', [
                        'model' => $name,
                        'keep_alive' => 0,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('ollama: no se pudo descargar modelo', [
                    'model' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ollama: modelos descargados de RAM (listo para ComfyUI)');
    }

    public function supportsModelUnload(): bool
    {
        return true;
    }
}
