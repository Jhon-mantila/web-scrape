<?php

namespace App\ProcessScraping;

class AiArticleResponseParser
{
    public function parse(string $raw): array
    {
        // 1. limpiar fences
        $text = $this->stripOuterCodeFences($raw);

        // 2. intentar extraer JSON si hay ruido
        $text = $this->extractJson($text);

        // 3. intento directo (rápido y limpio)
        $data = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->format($data);
        }

        // 4. fallback: arreglar JSON roto
        $fixed = $this->fixBrokenJson($text);
        $data = json_decode($fixed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::warning('JSON inválido desde IA', [
                'raw' => $raw,
                'cleaned' => $text,
            ]);

            return $this->empty();
        }

        return $this->format($data);
    }

    private function format(array $data): array
    {
        return [
            'generated_title' => $data['title'] ?? null,
            'excerpt' => $data['excerpt'] ?? null,
            'body_html' => $data['html'] ?? null,
        ];
    }

    private function empty(): array
    {
        return [
            'generated_title' => null,
            'excerpt' => null,
            'body_html' => null,
        ];
    }

    private function stripOuterCodeFences(string $text): string
    {
        $text = trim($text);

        // elimina ```json ... ```
        if (preg_match('/^```/', $text)) {
            $text = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        return trim($text);
    }

    private function extractJson(string $text): string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    private function fixBrokenJson(string $json): string
    {
        $inString = false;
        $escaped = false;
        $result = '';

        for ($i = 0; $i < strlen($json); $i++) {
            $char = $json[$i];

            if ($char === '"' && !$escaped) {
                $inString = !$inString;
            }

            // arreglar saltos de línea dentro de strings
            if ($inString && ($char === "\n" || $char === "\r")) {
                $result .= '\\n';
                continue;
            }

            $escaped = ($char === '\\' && !$escaped);
            $result .= $char;
        }

        return $result;
    }
}