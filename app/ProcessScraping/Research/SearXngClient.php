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

        $params = [
            'q' => $query,
            'format' => 'json',
            'language' => config('services.searxng.language', 'es-ES'),
        ];

        $engines = trim((string) config('services.searxng.engines', ''));
        if ($engines !== '') {
            $params['engines'] = $engines;
        }

        try {
            $response = Http::timeout((int) config('services.searxng.timeout'))
                ->acceptJson()
                ->get($url, $params);
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

        $payload = $response->json();
        $results = $payload['results'] ?? [];

        if ($results === []) {
            Log::warning('searxng: sin resultados', [
                'query' => $query,
                'engines' => $engines !== '' ? $engines : '(default)',
                'unresponsive_engines' => $payload['unresponsive_engines'] ?? [],
            ]);
        }

        $results = $this->filterRelevantResults($results, $query);

        return array_values(array_slice(array_map(function (array $item): array {
            return [
                'title' => (string) ($item['title'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
                'engine' => (string) ($item['engine'] ?? ''),
            ];
        }, $results), 0, $limit));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function filterRelevantResults(array $results, string $query): array
    {
        $queryWords = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower($query)) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 4,
        ));

        if ($queryWords === []) {
            return $results;
        }

        $relevant = array_values(array_filter(
            $results,
            function (array $item) use ($queryWords): bool {
                $haystack = mb_strtolower(($item['title'] ?? '').' '.($item['content'] ?? ''));

                foreach ($queryWords as $word) {
                    if (str_contains($haystack, $word)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return $relevant !== [] ? $relevant : $results;
    }
}
