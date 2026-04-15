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

                    $news = News::updateOrCreate(
                        ['url' => $item->url],
                        [
                            'title' => $item->title,
                            'image' => $item->image ?? null,
                            'source' => $item->source, // 👈 CLAVE
                            'category' => $item->category ?? null,
                        ]
                    );

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