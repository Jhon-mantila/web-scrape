<?php

namespace App\SendWordpress\Support;

use App\SendWordpress\WordPressAccount;
use App\SendWordpress\WordPressClient;

class WordpressInternalLinksInjector
{
    /**
     * @param  list<int>  $tagIds
     */
    public function inject(string $html, array $tagIds, WordPressClient $client, ?WordPressAccount $account): string
    {
        $max = max(0, (int) config('services.scraper.anime_news_internal_links', 3));

        if ($max === 0 || $tagIds === []) {
            return $html;
        }

        try {
            $posts = $client->listRecentPostsByTags($tagIds, $max, $account);
        } catch (\Throwable) {
            return $html;
        }

        if ($posts === []) {
            return $html;
        }

        $items = [];

        foreach ($posts as $post) {
            $link = trim((string) ($post['link'] ?? ''));
            $title = trim(strip_tags((string) ($post['title']['rendered'] ?? $post['title'] ?? '')));

            if ($link === '' || $title === '') {
                continue;
            }

            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                e($link),
                e($title),
            );
        }

        if ($items === []) {
            return $html;
        }

        $section = '<h2>También te puede interesar</h2><ul>'
            .implode('', array_slice($items, 0, $max))
            .'</ul>';

        return rtrim($html)."\n".$section;
    }
}
