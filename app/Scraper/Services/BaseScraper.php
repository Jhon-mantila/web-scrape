<?php

// app/Scraper/Services/BaseScraper.php
namespace App\Scraper\Services;

use GuzzleHttp\Client;

class BaseScraper
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; AnimeScraper/1.0)'
            ],
            'timeout' => 10
        ]);
    }

    protected function get(string $url): string
    {
        try {
            $response = $this->client->get($url);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error('Error en scraper', [
                'url' => $url,
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }
}
