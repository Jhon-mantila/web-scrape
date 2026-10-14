<?php

namespace App\ProcessScraping\Research;

class EnhancedResearchFormatter
{
    /**
     * @param  list<array{query: string, results: list<array{title: string, url: string, content: string, engine: string}>}>  $searches
     * @param  array<string, mixed>|null  $entities
     * @param  array<string, mixed>|null  $anilist
     * @param  list<array{url: string, title: string, text: string}>  $pages
     */
    public function format(array $searches, ?array $entities, ?array $anilist, array $pages): ?string
    {
        $blocks = [];
        $hasContent = false;

        if ($entities !== null && $entities !== []) {
            $blocks[] = 'ENTIDADES DETECTADAS (para contexto editorial):';
            $blocks[] = json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $blocks[] = '';
            $hasContent = true;
        }

        if ($anilist !== null && $anilist !== []) {
            $blocks[] = 'FICHA AniList (referencia de obra; no inventes datos fuera de aquí):';
            $blocks[] = json_encode($anilist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $blocks[] = '';
            $hasContent = true;
        }

        foreach ($searches as $search) {
            if ($search['results'] === []) {
                continue;
            }

            if (! $hasContent) {
                $blocks[] = 'INVESTIGACIÓN WEB COMPLEMENTARIA:';
                $blocks[] = '';
            }

            $hasContent = true;
            $blocks[] = 'Búsqueda: "'.$search['query'].'"';

            foreach ($search['results'] as $result) {
                $snippet = trim($result['content'] !== '' ? $result['content'] : $result['title']);
                $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
                $snippet = mb_substr($snippet, 0, 320);

                $blocks[] = '- '.$result['title'];
                $blocks[] = '  URL: '.$result['url'];
                $blocks[] = '  Extracto: '.$snippet;
            }

            $blocks[] = '';
        }

        foreach ($pages as $page) {
            $hasContent = true;
            $blocks[] = 'PÁGINA LEÍDA: '.$page['title'];
            $blocks[] = 'URL: '.$page['url'];
            $blocks[] = mb_substr($page['text'], 0, (int) config('services.research.page_context_chars', 4500));
            $blocks[] = '';
        }

        if (! $hasContent) {
            return null;
        }

        $blocks[] = 'Usa solo hechos verificables de los bloques anteriores y de la fuente scrapeada.';

        return trim(implode("\n", $blocks));
    }
}
