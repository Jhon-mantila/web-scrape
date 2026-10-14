<?php

namespace App\ProcessScraping\Prompts;

use App\ProcessScraping\Support\PromptContextLimiter;

class EntityExtractionPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
        Extraes entidades de noticias de anime/manga en inglés para orientar investigación en español.
        Responde SOLO con JSON válido, sin markdown.
        TXT;
    }

    public static function user(string $title, string $contentText, ?string $category): string
    {
        $contentText = PromptContextLimiter::contentText($contentText, 2500);

        return <<<TXT
        Título: {$title}
        Categoría fuente: {$category}

        Texto:
        {$contentText}

        Devuelve JSON:
        {
          "franchise": "nombre principal de la obra o franquicia (o vacío si no aplica)",
          "media_type": "anime|manga|game|other",
          "news_type": "anuncio|estreno|trailer|homenaje|industria|otro",
          "studio": "estudio o editorial si aparece, o null",
          "platforms": ["Crunchyroll", "Netflix"],
          "search_queries_es": ["2-3 búsquedas cortas en español para contexto LATAM/España"]
        }
        TXT;
    }
}
