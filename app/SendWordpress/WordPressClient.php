<?php

namespace App\SendWordpress;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordPressClient
{
    public function createPost(array $data): array
    {
        $url = config('services.wordpress.url') . '/wp-json/wp/v2/posts';

        $response = Http::withBasicAuth(
                config('services.wordpress.user'),
                config('services.wordpress.password')
            )
            ->timeout(60)
            ->post($url, $data);

        if ($response->failed()) {
            throw new RuntimeException('Error WordPress: '.$response->body());
        }

        return $response->json();
    }

    public function uploadMedia(string $filePath, string $filename): array
    {
        $url = config('services.wordpress.url').'/wp-json/wp/v2/media';

        $response = Http::withBasicAuth(
            config('services.wordpress.user'),
            config('services.wordpress.password')
        )
            ->timeout(120)
            ->attach(
                'file',
                file_get_contents($filePath),
                $filename,
                ['Content-Type' => mime_content_type($filePath) ?: 'image/jpeg']
            )
            ->post($url);

        if ($response->failed()) {
            throw new RuntimeException('Error subiendo media a WordPress: '.$response->body());
        }

        return $response->json();
    }
    
    public function getOrCreateCategory(string $name): int
    {
        $category = $this->findCategoryByName($name);

        if ($category) {
            return $category['id'];
        }

        $newCategory = $this->createCategory($name);

        return $newCategory['id'];
    }

    public function findCategoryByName(string $name): ?array
    {
        $url = config('services.wordpress.url') . '/wp-json/wp/v2/categories';

        $response = Http::withBasicAuth(
                config('services.wordpress.user'),
                config('services.wordpress.password')
            )
            ->get($url, [
                'search' => $name
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Error buscando categoría: '.$response->body());
        }

        $categories = collect($response->json());

        return $categories->firstWhere('name', $name);
    }

    public function createCategory(string $name): array
    {
        $url = config('services.wordpress.url') . '/wp-json/wp/v2/categories';

        $response = Http::withBasicAuth(
                config('services.wordpress.user'),
                config('services.wordpress.password')
            )
            ->post($url, [
                'name' => $name
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Error creando categoría: '.$response->body());
        }

        return $response->json();
    }
}