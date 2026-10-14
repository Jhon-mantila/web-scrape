<?php

namespace App\SendWordpress;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WordPressClient
{
    public function createPost(array $data, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/posts';

        $response = $this->http($account)
            ->timeout(60)
            ->post($url, $data);

        if ($response->failed()) {
            throw new RuntimeException('Error WordPress: '.$response->body());
        }

        return $response->json();
    }

    public function uploadMedia(
        string $filePath,
        string $filename,
        ?WordPressAccount $account = null,
        ?string $title = null,
    ): array {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/media';

        $request = $this->http($account)
            ->timeout(120)
            ->attach(
                'file',
                file_get_contents($filePath),
                $filename,
                ['Content-Type' => mime_content_type($filePath) ?: 'image/jpeg']
            );

        if ($title !== null && $title !== '') {
            $request = $request->attach('title', $title)->attach('alt_text', $title);
        }

        $response = $request->post($url);

        if ($response->failed()) {
            throw new RuntimeException('Error subiendo media a WordPress: '.$response->body());
        }

        $media = $response->json();

        if ($title !== null && $title !== '' && isset($media['id'])) {
            $media = $this->updateMedia((int) $media['id'], [
                'title' => $title,
                'alt_text' => $title,
            ], $account);
        }

        return $media;
    }

    /**
     * @param  array{title?: string, alt_text?: string, caption?: string}  $data
     */
    public function updateMedia(int $mediaId, array $data, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/media/'.$mediaId;

        $response = $this->http($account)
            ->timeout(30)
            ->post($url, $data);

        if ($response->failed()) {
            throw new RuntimeException('Error actualizando media en WordPress: '.$response->body());
        }

        return $response->json();
    }

    public function updatePost(int $postId, array $data, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/posts/'.$postId;

        $response = $this->http($account)
            ->timeout(60)
            ->post($url, $data);

        if ($response->failed()) {
            throw new RuntimeException('Error actualizando post WordPress: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function getPost(int $postId, ?WordPressAccount $account = null, array $fields = ['id', 'status', 'date', 'link', 'author']): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/posts/'.$postId;

        $response = $this->http($account)
            ->timeout(30)
            ->get($url, [
                '_fields' => implode(',', $fields),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error obteniendo post WordPress: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Posts publicados o programados (future) cuya fecha cae en el rango indicado.
     *
     * @return list<array<string, mixed>>
     */
    public function listPostsInDateRange(Carbon $from, Carbon $to, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/posts';
        $all = [];
        $page = 1;

        do {
            $response = $this->http($account)
                ->timeout(30)
                ->get($url, [
                    'status' => 'publish,future',
                    'after' => $from->toIso8601String(),
                    'before' => $to->toIso8601String(),
                    'per_page' => 100,
                    'page' => $page,
                    '_fields' => 'id,status,date,author',
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Error listando posts WordPress: '.$response->body());
            }

            /** @var list<array<string, mixed>> $batch */
            $batch = $response->json();
            $all = array_merge($all, $batch);
            $page++;
        } while (count($batch) === 100);

        return $all;
    }

    public function getOrCreateCategory(string $name, ?WordPressAccount $account = null): int
    {
        $category = $this->findCategoryByName($name, $account);

        if ($category) {
            return (int) $category['id'];
        }

        $slug = Str::slug($name);

        if ($slug !== '') {
            $category = $this->findCategoryBySlug($slug, $account);

            if ($category) {
                return (int) $category['id'];
            }
        }

        $newCategory = $this->createCategory($name, $account);

        return (int) $newCategory['id'];
    }

    public function findCategoryByName(string $name, ?WordPressAccount $account = null): ?array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/categories';

        $response = $this->http($account)
            ->get($url, [
                'search' => $name,
                'per_page' => 100,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error buscando categoría: '.$response->body());
        }

        $categories = collect($response->json());
        $needle = mb_strtolower(trim($name));

        return $categories->first(
            fn (array $category) => mb_strtolower(trim((string) ($category['name'] ?? ''))) === $needle,
        );
    }

    public function findCategoryBySlug(string $slug, ?WordPressAccount $account = null): ?array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $url = config('services.wordpress.url').'/wp-json/wp/v2/categories';

        $response = $this->http($account)->get($url, ['slug' => $slug]);

        if ($response->failed()) {
            throw new RuntimeException('Error buscando categoría por slug: '.$response->body());
        }

        /** @var list<array<string, mixed>> $items */
        $items = $response->json();

        return $items[0] ?? null;
    }

    public function createCategory(string $name, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/categories';

        $response = $this->http($account)
            ->post($url, [
                'name' => $name,
            ]);

        if ($response->failed()) {
            $existingId = $this->existingTermIdFromFailedCreate($response);

            if ($existingId !== null) {
                return ['id' => $existingId, 'name' => $name];
            }

            throw new RuntimeException('Error creando categoría: '.$response->body());
        }

        return $response->json();
    }

    public function getOrCreateTag(string $name, ?WordPressAccount $account = null): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Nombre de etiqueta vacío.');
        }

        $tag = $this->findTagByName($name, $account);

        if ($tag) {
            return (int) $tag['id'];
        }

        return (int) ($this->createTag($name, $account)['id'] ?? 0);
    }

    public function findTagByName(string $name, ?WordPressAccount $account = null): ?array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/tags';

        $response = $this->http($account)
            ->get($url, [
                'search' => $name,
                'per_page' => 20,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error buscando etiqueta: '.$response->body());
        }

        $tags = collect($response->json());

        return $tags->first(fn (array $tag) => mb_strtolower((string) ($tag['name'] ?? '')) === mb_strtolower($name));
    }

    /**
     * @return array<string, mixed>
     */
    public function createTag(string $name, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/tags';

        $response = $this->http($account)
            ->post($url, [
                'name' => $name,
            ]);

        if ($response->failed()) {
            $existingId = $this->existingTermIdFromFailedCreate($response);

            if ($existingId !== null) {
                return ['id' => $existingId, 'name' => $name];
            }

            throw new RuntimeException('Error creando etiqueta: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<array<string, mixed>>
     */
    public function listRecentPostsByTags(array $tagIds, int $limit, ?WordPressAccount $account = null): array
    {
        $tagIds = array_values(array_filter(array_map('intval', $tagIds)));

        if ($tagIds === [] || $limit < 1) {
            return [];
        }

        $url = config('services.wordpress.url').'/wp-json/wp/v2/posts';

        $response = $this->http($account)
            ->timeout(30)
            ->get($url, [
                'tags' => implode(',', $tagIds),
                'status' => 'publish',
                'per_page' => min(10, $limit + 2),
                'orderby' => 'date',
                'order' => 'desc',
                '_fields' => 'id,link,title,date',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error listando posts por etiqueta: '.$response->body());
        }

        /** @var list<array<string, mixed>> $posts */
        $posts = $response->json();

        return array_slice($posts, 0, $limit);
    }

    /**
     * WordPress responde 400 term_exists al crear un término duplicado; incluye term_id en el JSON.
     */
    private function existingTermIdFromFailedCreate(Response $response): ?int
    {
        if (! $response->failed()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ($body['code'] ?? '') !== 'term_exists') {
            return null;
        }

        $data = $body['data'] ?? null;

        if (is_array($data) && isset($data['term_id'])) {
            $termId = (int) $data['term_id'];

            return $termId > 0 ? $termId : null;
        }

        $additional = $body['additional_data'] ?? null;

        if (is_array($additional) && isset($additional[0])) {
            $termId = (int) $additional[0];

            return $termId > 0 ? $termId : null;
        }

        return null;
    }

    private function http(?WordPressAccount $account = null): PendingRequest
    {
        $account ??= $this->defaultAccount();

        return Http::withBasicAuth($account->user, $account->password);
    }

    private function defaultAccount(): WordPressAccount
    {
        $user = (string) config('services.wordpress.user');
        $password = (string) config('services.wordpress.password');

        if ($user === '' || $password === '') {
            throw new RuntimeException('WORDPRESS_USER y WORDPRESS_PASSWORD son obligatorios.');
        }

        return new WordPressAccount($user, $password, 'primary');
    }
}
