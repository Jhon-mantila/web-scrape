<?php

namespace App\ProcessScraping\Prompts;

use App\ProcessScraping\Support\PromptContextLimiter;

class FactSheetPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
        Construyes una ficha de hechos verificables para redactores de EsquinaAnime.
        Cada hecho debe tener source_url cuando exista en el material.
        No inventes datos. Si falta información, omítela o marca incertidumbre en "notas".
        Responde SOLO JSON válido.
        TXT;
    }

    public static function user(
        string $title,
        string $sourceUrl,
        string $contentText,
        ?string $researchContext,
        string $groupedSourcesBlock = '',
    ): string {
        $contentText = PromptContextLimiter::contentText($contentText, 5000);
        $research = $researchContext !== null && trim($researchContext) !== ''
            ? PromptContextLimiter::contentText($researchContext, 8000)
            : '(sin investigación complementaria)';

        $groupedSection = trim($groupedSourcesBlock) !== ''
            ? "\n\n{$groupedSourcesBlock}\n"
            : '';

        return <<<TXT
        Noticia original (ANN): {$title}
        URL fuente: {$sourceUrl}

        CONTENIDO SCRAPEADO:
        {$contentText}
        {$groupedSection}
        MATERIAL DE INVESTIGACIÓN (AniList, web, páginas leídas):
        {$research}

        Genera JSON:
        {
          "estado": "OK|INSUFICIENTE",
          "franchise": "string|null",
          "resumen_es": "2-3 frases en español con lo esencial",
          "hechos": [
            {"dato": "texto del hecho", "source_url": "https://...", "source_label": "ANN|AniList|medio"}
          ],
          "disponibilidad_es": {
            "streaming": ["solo si consta en el material"],
            "manga_editorial": ["solo si consta"],
            "notas": "string|null"
          },
          "contexto_obra": "párrafo breve solo con datos de AniList o fuentes, o null"
        }

        Usa estado INSUFICIENTE si no hay material para un artículo útil (~350+ palabras) sin inventar.
        TXT;
    }
}
