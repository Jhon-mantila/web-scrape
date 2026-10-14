<?php

namespace App\ProcessScraping\Ai\Support;

class JsonPayloadParser
{
    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $raw): ?array
    {
        $text = trim($raw);

        if (preg_match('/^```/', $text)) {
            $text = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }
}
