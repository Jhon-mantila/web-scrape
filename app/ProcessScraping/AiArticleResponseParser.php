<?php

namespace App\ProcessScraping;

class AiArticleResponseParser
{
    /**
     * @return array{generated_title: string|null, excerpt: string|null, body_html: string|null}
     */
    public function parse(string $raw): array
    {
        $text = $this->stripOuterCodeFences($raw);
        // 👇 NUEVO
        $text = $this->fixHtmlField($text);
        // 🔥 FIX CRÍTICO: escapar saltos de línea dentro de strings
        $text = $this->fixBrokenJson($text);
    
        $data = json_decode($text, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::warning('JSON inválido desde IA', [
                'raw' => $raw,
            ]);
    
            return [
                'generated_title' => null,
                'excerpt' => null,
                'body_html' => null,
            ];
        }
    
        return [
            'generated_title' => $data['title'] ?? null,
            'excerpt' => $data['excerpt'] ?? null,
            'body_html' => $data['html'] ?? null,
        ];
    }

    private function stripOuterCodeFences(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            // quitar ```json o ```
            $text = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        return trim($text);
    }

    private function fixHtmlField(string $json): string
    {
        return preg_replace_callback(
            '/"html"\s*:\s*(<.+?>.*<\/.+?>)/s',
            function ($matches) {
                $html = $matches[1];

                // escapar comillas
                $html = str_replace('"', '\\"', $html);

                // escapar saltos de línea
                $html = str_replace(["\n", "\r"], '\\n', $html);

                return '"html": "' . $html . '"';
            },
            $json
        );
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

            // 🔥 si estamos dentro de string y hay salto de línea → escapar
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