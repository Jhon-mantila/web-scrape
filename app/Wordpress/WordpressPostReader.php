<?php

namespace App\Wordpress;

use App\SocialPublishing\Enums\WordpressSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordpressPostReader
{
    /**
     * Artículos publicados después de la fecha indicada (novedades en el blog).
     *
     * @return list<array{
     *     wp_post_id: int,
     *     title: string,
     *     excerpt: ?string,
     *     url: string,
     *     featured_image_url: ?string,
     *     published_at_wp: ?Carbon
     * }>
     */
    public function fetchNewPostsSince(WordpressSite $site, ?Carbon $after, int $maxPages, int $perPage): array
    {
        if ($after === null) {
            return [];
        }

        $params = [
            'status' => 'publish',
            'per_page' => $perPage,
            '_embed' => 'wp:featuredmedia',
            'orderby' => 'date',
            'order' => 'asc',
            'after' => $after->toIso8601String(),
        ];

        return $this->fetchPaginated($site, $params, $maxPages);
    }

    /**
     * Siguiente tanda de artículos más antiguos (paginación hacia atrás).
     *
     * @return array{
     *     posts: list<array{
     *         wp_post_id: int,
     *         title: string,
     *         excerpt: ?string,
     *         url: string,
     *         featured_image_url: ?string,
     *         published_at_wp: ?Carbon
     *     }>,
     *     pages_fetched: int,
     *     archive_exhausted: bool
     * }
     */
    public function fetchBackfillBatch(
        WordpressSite $site,
        int $startPage,
        int $pageCount,
        int $perPage,
    ): array {
        $params = [
            'status' => 'publish',
            'per_page' => $perPage,
            '_embed' => 'wp:featuredmedia',
            'orderby' => 'date',
            'order' => 'desc',
        ];

        $posts = [];
        $pagesFetched = 0;
        $archiveExhausted = false;
        $endPage = $startPage + max(1, $pageCount) - 1;

        for ($page = $startPage; $page <= $endPage; $page++) {
            $batch = $this->fetchPage($site, array_merge($params, ['page' => $page]));

            if ($batch === []) {
                $archiveExhausted = true;
                break;
            }

            $pagesFetched++;

            foreach ($batch as $row) {
                $mapped = $this->mapPost($row);

                if ($mapped !== null) {
                    $posts[] = $mapped;
                }
            }

            if (count($batch) < $perPage) {
                $archiveExhausted = true;
                break;
            }
        }

        return [
            'posts' => $posts,
            'pages_fetched' => $pagesFetched,
            'archive_exhausted' => $archiveExhausted,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseParams
     * @return list<array{
     *     wp_post_id: int,
     *     title: string,
     *     excerpt: ?string,
     *     url: string,
     *     featured_image_url: ?string,
     *     published_at_wp: ?Carbon
     * }>
     */
    private function fetchPaginated(WordpressSite $site, array $baseParams, int $maxPages): array
    {
        $posts = [];
        $perPage = (int) ($baseParams['per_page'] ?? 20);

        for ($page = 1; $page <= max(1, $maxPages); $page++) {
            $batch = $this->fetchPage($site, array_merge($baseParams, ['page' => $page]));

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $mapped = $this->mapPost($row);

                if ($mapped !== null) {
                    $posts[] = $mapped;
                }
            }

            if (count($batch) < $perPage) {
                break;
            }
        }

        return $posts;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchPage(WordpressSite $site, array $params): array
    {
        $baseUrl = $site->url();

        if ($baseUrl === null) {
            throw new RuntimeException("WordPress ({$site->label()}) sin URL configurada.");
        }

        $page = (int) ($params['page'] ?? 1);

        $response = Http::timeout(45)
            ->get("{$baseUrl}/wp-json/wp/v2/posts", $params);

        if ($response->status() === 400 && str_contains($response->body(), 'rest_post_invalid_page_number')) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "WordPress ({$site->label()}) página {$page}: {$response->status()} — {$response->body()}",
            );
        }

        /** @var list<array<string, mixed>> $batch */
        $batch = $response->json();

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     wp_post_id: int,
     *     title: string,
     *     excerpt: ?string,
     *     url: string,
     *     featured_image_url: ?string,
     *     published_at_wp: ?Carbon
     * }|null
     */
    private function mapPost(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        $link = is_string($row['link'] ?? null) ? $row['link'] : null;

        if ($id <= 0 || $link === null || $link === '') {
            return null;
        }

        $title = $this->renderedText($row['title'] ?? null) ?? "Artículo #{$id}";
        $excerpt = $this->plainExcerpt($row['excerpt'] ?? null);
        $publishedAt = isset($row['date']) ? Carbon::parse((string) $row['date']) : null;

        return [
            'wp_post_id' => $id,
            'title' => $title,
            'excerpt' => $excerpt,
            'url' => $link,
            'featured_image_url' => $this->featuredImageUrl($row),
            'published_at_wp' => $publishedAt,
        ];
    }

    private function renderedText(mixed $field): ?string
    {
        if (! is_array($field)) {
            return is_string($field) && $field !== '' ? html_entity_decode(strip_tags($field)) : null;
        }

        $rendered = $field['rendered'] ?? null;

        if (! is_string($rendered) || $rendered === '') {
            return null;
        }

        return html_entity_decode(trim(strip_tags($rendered)));
    }

    private function plainExcerpt(mixed $field): ?string
    {
        $text = $this->renderedText($field);

        if ($text === null || $text === '') {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'…' : $text;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function featuredImageUrl(array $row): ?string
    {
        $embedded = $row['_embedded']['wp:featuredmedia'][0] ?? null;

        if (! is_array($embedded)) {
            return null;
        }

        $sourceUrl = $embedded['source_url'] ?? null;

        return is_string($sourceUrl) && $sourceUrl !== '' ? $sourceUrl : null;
    }
}
