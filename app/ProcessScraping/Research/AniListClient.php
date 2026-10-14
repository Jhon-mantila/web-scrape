<?php

namespace App\ProcessScraping\Research;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AniListClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function lookupMedia(string $search, string $mediaType = 'anime'): ?array
    {
        if (! config('services.anilist.enabled')) {
            return null;
        }

        $search = trim($search);

        if ($search === '') {
            return null;
        }

        $type = mb_strtoupper($mediaType) === 'MANGA' ? 'MANGA' : 'ANIME';

        $query = <<<'GQL'
        query ($search: String, $type: MediaType) {
          Page(page: 1, perPage: 1) {
            media(search: $search, type: $type, sort: POPULARITY_DESC) {
              id
              format
              episodes
              chapters
              status
              seasonYear
              popularity
              averageScore
              siteUrl
              title { userPreferred romaji english native }
              studios(isMain: true) { nodes { name } }
              description(asHtml: false)
              externalLinks { url site }
            }
          }
        }
        GQL;

        try {
            $response = Http::timeout((int) config('services.anilist.timeout', 20))
                ->acceptJson()
                ->post((string) config('services.anilist.url', 'https://graphql.anilist.co'), [
                    'query' => $query,
                    'variables' => [
                        'search' => $search,
                        'type' => $type,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('anilist: conexión fallida', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('anilist: respuesta HTTP', ['status' => $response->status()]);

            return null;
        }

        $media = $response->json('data.Page.media.0');

        if (! is_array($media)) {
            return null;
        }

        return $this->normalizeMedia($media);
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    private function normalizeMedia(array $media): array
    {
        $title = $media['title'] ?? [];
        $studios = $media['studios']['nodes'] ?? [];

        return [
            'id' => $media['id'] ?? null,
            'title' => is_array($title) ? ($title['userPreferred'] ?? $title['romaji'] ?? $title['english'] ?? null) : null,
            'format' => $media['format'] ?? null,
            'episodes' => $media['episodes'] ?? null,
            'chapters' => $media['chapters'] ?? null,
            'status' => $media['status'] ?? null,
            'season_year' => $media['seasonYear'] ?? null,
            'popularity' => $media['popularity'] ?? null,
            'average_score' => $media['averageScore'] ?? null,
            'site_url' => $media['siteUrl'] ?? null,
            'studios' => array_values(array_filter(array_map(
                fn ($node) => is_array($node) ? ($node['name'] ?? null) : null,
                is_array($studios) ? $studios : [],
            ))),
            'description' => $this->cleanDescription((string) ($media['description'] ?? '')),
            'external_links' => $media['externalLinks'] ?? [],
        ];
    }

    private function cleanDescription(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim(mb_substr(trim($text), 0, 1200));
    }
}
