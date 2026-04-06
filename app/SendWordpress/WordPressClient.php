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
}