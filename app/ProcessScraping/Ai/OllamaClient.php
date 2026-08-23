<?php

namespace App\ProcessScraping\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OllamaClient
{
    public function generate(string $system, string $prompt, ?string $model = null): string
    {
        $url = rtrim(config('services.ollama.url'), '/').'/api/generate';
        $model ??= config('services.ollama.model');

        $payload = [
            'model' => $model,
            'system' => $system,
            'prompt' => $prompt,
            'stream' => false,
        ];

        if (config('services.ollama.format_json')) {
            $payload['format'] = 'json';
        }

        if (str_contains($model, 'qwen3')) {
            $payload['think'] = false;
        }

        $options = array_filter([
            'temperature' => (float) config('services.ollama.temperature'),
            'num_ctx' => (int) config('services.ollama.num_ctx'),
            'num_predict' => (int) config('services.ollama.num_predict'),
        ], fn ($value) => $value !== null && $value !== 0 && $value !== '');

        if ($options !== []) {
            $payload['options'] = $options;
        }

        try {
            $response = Http::timeout((int) config('services.ollama.timeout'))
                ->connectTimeout(15)
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(
                'No se pudo conectar con Ollama en '.$url.': '.$e->getMessage(),
                0,
                $e
            );
        }

        if ($response->failed()) {
            $body = $response->body();
            $snippet = $body !== '' ? ' Respuesta: '.mb_substr($body, 0, 500) : '';
            throw new RuntimeException('Ollama HTTP '.$response->status().' en '.$url.'.'.$snippet);
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
}
