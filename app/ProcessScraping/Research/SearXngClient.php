<?php

namespace App\ProcessScraping\Research;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SearXngClient
{
    /**
     * @return list<array{title: string, url: string, content: string, engine: string}>
     */
    public function search(string $query, int $limit = 3): array
    {
        if (! config('services.searxng.enabled')) {
            return [];
        }

        $url = rtrim(config('services.searxng.url'), '/').'/search';

        try {
            $response = Http::timeout((int) config('services.searxng.timeout'))
                ->acceptJson()
                ->get($url, [
                    'q' => $query,
                    'format' => 'json',
                    'language' => config('services.searxng.language', 'es-ES'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('searxng: conexión fallida', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('No se pudo conectar con SearXNG: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('SearXNG HTTP '.$response->status().': '.$response->body());
        }

        $results = $response->json('results') ?? [];

        return array_values(array_slice(array_map(function (array $item): array {
            return [
                'title' => (string) ($item['title'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
                'engine' => (string) ($item['engine'] ?? ''),
            ];
        }, $results), 0, $limit));
    }
}
