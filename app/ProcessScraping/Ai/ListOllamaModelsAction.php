<?php

namespace App\ProcessScraping\Ai;

use App\ProcessScraping\Ai\Support\OllamaConnectivity;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ListOllamaModelsAction
{
    /**
     * @return list<array{name: string, size: int|null}>
     */
    public function execute(string $target): array
    {
        if ($target === OllamaConnectivity::TARGET_REMOTE && ! OllamaConnectivity::remoteConfigured()) {
            throw new RuntimeException('OLLAMA_REMOTE_URL no está configurada en .env.');
        }

        $url = OllamaConnectivity::baseUrlForTarget($target).'/api/tags';

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->acceptJson()
                ->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con Ollama ('.$target.'): '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Ollama respondió '.$response->status().' al listar modelos ('.$target.').',
            );
        }

        $models = $response->json('models');

        if (! is_array($models)) {
            return [];
        }

        $out = [];

        foreach ($models as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $size = isset($entry['size']) && is_numeric($entry['size'])
                ? (int) $entry['size']
                : null;

            $out[] = ['name' => $name, 'size' => $size];
        }

        usort($out, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }
}
