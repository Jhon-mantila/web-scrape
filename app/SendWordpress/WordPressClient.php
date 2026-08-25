<?php

namespace App\SendWordpress;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
            return $category['id'];
        }

        $newCategory = $this->createCategory($name, $account);

        return $newCategory['id'];
    }

    public function findCategoryByName(string $name, ?WordPressAccount $account = null): ?array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/categories';

        $response = $this->http($account)
            ->get($url, [
                'search' => $name,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error buscando categoría: '.$response->body());
        }

        $categories = collect($response->json());

        return $categories->firstWhere('name', $name);
    }

    public function createCategory(string $name, ?WordPressAccount $account = null): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/categories';

        $response = $this->http($account)
            ->post($url, [
                'name' => $name,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Error creando categoría: '.$response->body());
        }

        return $response->json();
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
