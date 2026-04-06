<?php

namespace App\ProcessScraping;

class ArticleGenerationPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
Eres un editor de noticias especializado en anime y manga. Escribes en español neutro, claro y correcto.
Respetas los hechos de la fuente: no inventas fechas, nombres, cifras ni citas que no aparezcan en el material.
Tu salida sigue EXACTAMENTE el formato de secciones pedido al final del mensaje de usuario, sin texto adicional fuera de ese formato.
TXT;
    }

    public static function user(
        string $title,
        string $contentText,
        ?string $rawHtml = null,
        ?string $source = null,
    ): string {

        $extraInstructions = '';

        if ($source === 'anime_news') {
            $extraInstructions = <<<TXT
            CONTEXTO DE FUENTE:
            - Esta noticia proviene de un medio en inglés (tipo Anime News Network).
            - Traduce y adapta el contenido al español.
            - Mantén el enfoque informativo (noticia).
            - Respeta fechas, declaraciones y fuentes.
            - Si hay citas, puedes parafrasearlas (no literal).
            TXT;
        }

        if ($source === 'esquinaweb') {
            $extraInstructions = <<<TXT
            CONTEXTO DE FUENTE:
            - Esta noticia proviene de un blog en español.
            - Mejora redacción, SEO y claridad.
            - Evita redundancias.
            TXT;
        }

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

        $blocks[] = '';
        $blocks[] = $extraInstructions;
        $blocks[] = <<<'TXT'
        Reescribe la información como un artículo propio para publicación web (no copies párrafos literales).

        Requisitos del artículo:
        - Tono informativo y agradable a la lectura; pensado para SEO sin relleno.
        - El cuerpo debe ser HTML válido listo para pegar en WordPress: usa <p>, <h2>, <h3>, <strong>, <ul><li> cuando corresponda.
        - No uses <html>, <body>, ni <h1> (el título del post se define aparte).
        - Mantén nombres de obras, personajes y estudios tal como en la fuente.

        Responde SOLO en formato JSON válido.

        Estructura obligatoria:

        {
        "title": "string",
        "excerpt": "string",
        "html": "string (todo el HTML debe ir en UNA SOLA LÍNEA y escapado correctamente)"
        }

        Reglas:
        - "title": título optimizado SEO.
        - "excerpt": resumen de 1–2 frases, máximo 300 caracteres, sin HTML.
        - "html": contenido del artículo en HTML válido (<p>, <h2>, <ul>, etc.).
        - NO agregues texto fuera del JSON.
        - NO expliques nada.
        - NO uses markdown.
        - La respuesta debe ser JSON válido parseable.
        - El campo "html" DEBE ser un string JSON válido.
        - TODO el HTML debe estar entre comillas.
        - NO uses saltos de línea reales dentro del HTML.
        - Usa \n si necesitas saltos de línea.
        - Escapa comillas internas como \".

        Reglas adicionales para "title":
        - NO repitas el título original literalmente.
        - Mejora el título para SEO.
        - Hazlo atractivo para clics (CTR).
        - Usa palabras como: análisis, reseña, dónde ver, historia, personajes.
        - Máximo 70 caracteres.
        TXT;

        return implode("\n", $blocks);
    }
}
