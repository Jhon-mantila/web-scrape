<?php

namespace App\ProcessScraping\Research;

class ResearchContextFormatter
{
    /**
     * @param  list<array{query: string, results: list<array{title: string, url: string, content: string, engine: string}>}>  $searches
     */
    public function format(array $searches): ?string
    {
        if ($searches === []) {
            return null;
        }

        $blocks = [];
        $hasResults = false;

        foreach ($searches as $search) {
            if ($search['results'] === []) {
                continue;
            }

            if (! $hasResults) {
                $blocks[] = 'CONTEXTO DE INVESTIGACIÓN WEB (complemento; NO reemplaza la fuente original):';
                $blocks[] = 'Usa solo hechos que aparezcan aquí o en el contenido de referencia. Si hay conflicto, prioriza la fuente scrapeada.';
                $blocks[] = '';
                $hasResults = true;
            }

            $blocks[] = 'Búsqueda: "'.$search['query'].'"';

            foreach ($search['results'] as $result) {
                $snippet = trim($result['content'] !== '' ? $result['content'] : $result['title']);
                $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
                $snippet = mb_substr($snippet, 0, 280);

                $blocks[] = '- '.$result['title'];
                $blocks[] = '  URL: '.$result['url'];
                $blocks[] = '  Extracto: '.$snippet;
            }

            $blocks[] = '';
        }

        if (! $hasResults) {
            return null;
        }

        return trim(implode("\n", $blocks));
    }
}
