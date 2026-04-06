<?php

namespace App\ProcessScraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient
{
    public function generate(string $system, string $prompt, ?string $model = null): string
    {
        $url = rtrim(config('services.ollama.url'), '/').'/api/generate';
        $model ??= config('services.ollama.model');

        try {
            $response = Http::timeout((int) config('services.ollama.timeout'))
                ->connectTimeout(15)
                ->acceptJson()
                ->post($url, [
                    'model' => $model,
                    'system' => $system,
                    'prompt' => $prompt,
                    'stream' => false,
                ]);
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
}
