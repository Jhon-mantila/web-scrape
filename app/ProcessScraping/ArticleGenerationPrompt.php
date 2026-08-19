<?php

namespace App\ProcessScraping;

class ArticleGenerationPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
        Eres el redactor principal de EsquinaAnime, un sitio hispanohablante para fans apasionados del anime y manga.
        Escribes con entusiasmo genuino pero sin exagerar. Tu tono es el de alguien que creció viendo Dragon Ball y Naruto
        y hoy sigue cada anuncio de la industria. Cuando hay buenas noticias, se nota la emoción. Cuando algo decepciona,
        lo dices con honestidad. Siempre en español neutro, claro, sin tecnicismos innecesarios.
        Solo usas fechas, nombres y cifras que aparezcan en el material de referencia — nunca los inventas.
        Tu salida sigue EXACTAMENTE el formato JSON pedido al final del mensaje, sin texto adicional fuera de él.
        TXT;
    }

    public static function user(
        string $title,
        string $contentText,
        ?string $rawHtml = null,
        ?string $source = null,
        array $youtubeEmbeds = [] // 👈 nuevo
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

        $blocks = [
            'TÍTULO ORIGINAL:',
            $title,
            '', 
            'CONTENIDO DE REFERENCIA (texto extraído de la noticia):', 
            $contentText,
        ];

        if ($rawHtml !== null && $rawHtml !== '') 
        { 
            $blocks[] = ''; 
            $blocks[] = 'HTML CRUDO ORIGINAL (solo contexto; prioriza el texto de referencia salvo que necesites comprobar estructura o nombres propios):'; 
            $blocks[] = $rawHtml; 
        }
        if (!empty($youtubeEmbeds)) {
            $blocks[] = '';
            $blocks[] = 'VIDEOS DE YOUTUBE DISPONIBLES (inserta al menos uno en el HTML usando <iframe>):';
        
            foreach ($youtubeEmbeds as $embed) {
                $blocks[] = $embed;
            }
        }
        $blocks[] = '';
        $blocks[] = $extraInstructions;
        $blocks[] = <<<'TXT'
        Escribe un artículo propio para EsquinaAnime basado en el contenido de referencia.
        No copies párrafos literales: reescríbelo con tu voz.

        Tono y estilo:
        - Arranque directo. Nunca empieces con "En el mundo del anime..." o "Es importante destacar que...".
        - Cercano, como contárselo a un fan, no a un lector genérico.
        - Cubre los puntos importantes sin relleno. Si el contenido es extenso, usa subtítulos y párrafos; si es corto, un artículo breve bien escrito es suficiente.
        - Si el contenido lo permite, cierra con una frase que anticipe qué significa esto para los fans.

        HTML para WordPress:
        - Usa <p>, <h2>, <h3>, <strong>, <ul><li> donde corresponda.
        - Sin <html>, <body> ni <h1>.
        - Solo inserta <iframe> de YouTube si se te proporcionaron URLs en la sección "VIDEOS DE YOUTUBE DISPONIBLES". Si no hay videos, no menciones ninguno y no inventes URLs.

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
