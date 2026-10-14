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
            $firstError = $summary['ai']['errors'][0]['message'] ?? null;

            if (is_string($firstError) && $firstError !== '') {
                $parts[] = mb_substr($firstError, 0, 220);
            }
        }

        $timings = $summary['timings'] ?? [];

        if (is_array($timings) && $timings !== []) {
            $total = array_sum(array_map('floatval', $timings));

            if ($total > 0) {
                $parts[] = 'Tiempo '.$this->formatDuration($total);
            }
        }

        return 'Pipeline: '.implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $wordpress
     */
    public function formatWordpress(array $wordpress, string $mode): string
    {
        $line = 'WordPress ('.$mode.'): '.$this->wordpressLine($wordpress, $mode);
        $timings = $wordpress['timings'] ?? null;

        if (is_array($timings) && $timings !== []) {
            $total = array_sum(array_map('floatval', $timings));

            if ($total > 0) {
                $line .= ' · Tiempo '.$this->formatDuration($total);
            }
        }

        return $line;
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

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d h %d min %d seg', $hours, $minutes, $secs);
        }

        if ($minutes > 0) {
            return sprintf('%d min %d seg', $minutes, $secs);
        }

        return sprintf('%d seg', $secs);
    }
}
