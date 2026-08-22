<?php

namespace App\ProcessScraping\Prompts;

use App\ProcessScraping\Support\PromptContextLimiter;

class ArticleGenerationPrompt
{
    public static function system(?string $articleType = null): string
    {
        return PromptLibrary::system($articleType ?? 'default');
    }

    public static function user(
        string $title,
        string $contentText,
        ?string $rawHtml = null,
        ?string $source = null,
        array $youtubeEmbeds = [],
        ?string $articleType = null,
    ): string {

        $extraInstructions = '';

        if ($source === 'anime_news') {
            $extraInstructions = <<<TXT
            CONTEXTO DE FUENTE:
            - Noticia proveniente de un medio en inglés (tipo Anime News Network).
            - Traduce y adapta al español. Respeta fechas, declaraciones y fuentes.
            - Si hay citas, parafraséalas; no las traduzcas literal.
            TXT;
        }

        if ($source === 'esquinaweb') {
            $extraInstructions = <<<TXT
            CONTEXTO DE FUENTE:
            - Noticia de un blog en español.
            - Mejora redacción, claridad y SEO. Elimina redundancias.
            TXT;
        }

        $type = $articleType ?? 'default';
        $toneExtra = PromptLibrary::userExtra($type);
        $contentText = PromptContextLimiter::contentText($contentText);
        $rawHtml = PromptContextLimiter::rawHtml($rawHtml);

        $blocks = [
            'TÍTULO ORIGINAL:',
            $title,
            '',
            'CONTENIDO DE REFERENCIA (texto extraído de la noticia):',
            $contentText,
        ];

        if ($rawHtml !== null && $rawHtml !== '') {
            $blocks[] = '';
            $blocks[] = 'HTML CRUDO ORIGINAL (solo contexto; prioriza el texto de referencia salvo que necesites comprobar estructura o nombres propios):';
            $blocks[] = $rawHtml;
        }

        if (! empty($youtubeEmbeds)) {
            $blocks[] = '';
            $blocks[] = 'VIDEOS DE YOUTUBE DISPONIBLES (solo puedes usar estos; inserta uno como máximo):';

            foreach ($youtubeEmbeds as $embed) {
                $blocks[] = $embed;
            }
        } else {
            $blocks[] = '';
            $blocks[] = 'VIDEOS: NO HAY VIDEOS EN ESTA NOTICIA.';
            $blocks[] = 'PROHIBIDO inventar URLs, IDs o embeds de YouTube.';
            $blocks[] = 'PROHIBIDO insertar <iframe>, <figure> de video o mencionar "mira el video" / "trailer" embebido si no hay video en la fuente.';
        }

        $blocks[] = '';
        $blocks[] = $extraInstructions;
        $blocks[] = '';
        $blocks[] = 'TIPO DE ARTÍCULO DETECTADO: '.$type;
        $blocks[] = $toneExtra;
        $blocks[] = <<<'TXT'
        Escribe un artículo propio para EsquinaAnime basado en el contenido de referencia.
        No copies párrafos literales: reescríbelo con tu voz.

        Tono y estilo:
        - Arranque directo. Nunca empieces con "En el mundo del anime..." o "Es importante destacar que...".
        - Cercano, como contárselo a un fan, no a un lector genérico.
        - Cubre los puntos importantes sin relleno. Si el contenido es extenso, usa subtítulos y párrafos; si es corto, un artículo breve bien escrito es suficiente.

        HTML para WordPress:
        - Usa <p>, <h2>, <h3>, <strong>, <ul><li> donde corresponda.
        - Sin <html>, <body> ni <h1>.
        - Solo inserta video si la sección "VIDEOS DE YOUTUBE DISPONIBLES" tiene URLs. Si dice "NO HAY VIDEOS", el HTML no debe contener iframe ni figure de YouTube.
        - Nunca inventes IDs de YouTube (como 00000000000) ni dejes src vacío.

        Responde únicamente con JSON válido:
        {
            "title": "string — título SEO, máximo 70 caracteres, atractivo para clics, no repitas el original",
            "excerpt": "string — resumen de 1-2 frases, máximo 300 caracteres, sin HTML",
            "html": "string — todo el HTML en una sola línea, comillas internas escapadas como \""
        }
        Sin texto adicional. Sin markdown. Solo el JSON parseable.
        TXT;

        return implode("\n", $blocks);
    }
}
