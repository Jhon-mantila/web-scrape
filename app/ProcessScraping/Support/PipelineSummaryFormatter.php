<?php

namespace App\ProcessScraping\Support;

class PipelineSummaryFormatter
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $options
     */
    public function formatPipeline(array $summary, array $options): string
    {
        $parts = [];

        if (! ($options['skip_scrape'] ?? false)) {
            $parts[] = 'Listado: '.($summary['scrape_news'] ?? 0).' nuevas';
        }

        $parts[] = 'Detalles OK '.$summary['details']['success'].'/'.$summary['details']['processed'];
        $parts[] = 'Imágenes '.$summary['images']['downloaded'].' desc. · '.$summary['images']['generated'].' FLUX';

        if (! ($options['skip_research'] ?? false)) {
            $parts[] = 'Research OK '.$summary['research']['success'].'/'.$summary['research']['processed'];
        }

        $parts[] = 'IA OK '.$summary['ai']['success'].'/'.$summary['ai']['processed'];

        if ($options['send_wordpress'] ?? false) {
            $parts[] = $this->wordpressLine($summary['wordpress'], (string) ($options['mode'] ?? 'draft'));
        } else {
            $parts[] = 'WP omitido';
        }

        if (($summary['ai']['failed'] ?? 0) > 0) {
            $parts[] = 'IA fallidas: '.$summary['ai']['failed'];
        }

        return 'Pipeline: '.implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $wordpress
     */
    public function formatWordpress(array $wordpress, string $mode): string
    {
        return 'WordPress ('.$mode.'): '.$this->wordpressLine($wordpress, $mode);
    }

    /**
     * @param  array<string, mixed>  $wordpress
     */
    private function wordpressLine(array $wordpress, string $mode): string
    {
        $line = 'WP ('.$mode.') OK '.$wordpress['success'].'/'.$wordpress['processed'];

        if (($wordpress['by_author'] ?? []) !== []) {
            $authors = [];

            foreach ($wordpress['by_author'] as $author => $count) {
                $authors[] = "{$author}: {$count}";
            }

            $line .= ' ['.implode(', ', $authors).']';
        }

        if ($mode === 'schedule' && ($wordpress['scheduled'] ?? []) !== []) {
            $line .= ' · '.count($wordpress['scheduled']).' programados';
        }

        return $line;
    }
}
