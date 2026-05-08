<?php

namespace App\Scraper\Actions;

use App\Scraper\Sources\AnimeNewsScraper;
use App\Scraper\Sources\EsquinaAnimeScraper;
use App\Models\News;
use App\Models\NewsDetail;
use Illuminate\Support\Facades\Log;

class ScrapeNewsAction
{
    public function execute(): array
    {
        $sources = [
            new AnimeNewsScraper(),
            //new EsquinaAnimeScraper(),
        ];

        $saved = [];

        foreach ($sources as $source) {

            try {
                $items = $source->scrape();

                foreach ($items as $item) {

                    // Evita duplicados
                    $exists = News::where('url', $item->url)
                        ->orWhere('title', $item->title)
                        ->exists();

                    if ($exists) {
                        Log::info('Noticia duplicada omitida', [
                            'title' => $item->title,
                            'url' => $item->url
                        ]);

                        continue;
                    }

                    $news = News::create([
                        'title' => $item->title,
                        'url' => $item->url,
                        'image' => $item->image ?? null,
                        'source' => $item->source,
                        'category' => $item->category ?? null,
                    ]);

                    NewsDetail::firstOrCreate(
                        ['news_id' => $news->id],
                        ['status' => 'pending', 'attempt_count' => 0]
                    );

                    $saved[] = $news;
                }

            } catch (\Throwable $e) {
                // 👇 importante para debug sin romper todo
                Log::error('Error en scraper: ' . get_class($source), [
                    'message' => $e->getMessage()
                ]);
            }
        }

        return $saved;
    }
}