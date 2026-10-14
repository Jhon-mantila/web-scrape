<?php

namespace App\ProcessScraping\Prompts;

class AnimeNewsWritingGuidelines
{
    public static function sourceContext(): string
    {
        return <<<'TXT'
        CONTEXTO DE FUENTE:
        - Noticia basada en Anime News Network (texto de referencia en inglés).
        - NO traduzcas literalmente: redacta un artículo original en español neutro (Latinoamérica y España).
        - Respeta fechas, nombres propios, estudios y cifras que aparezcan en la referencia o en la investigación.
        - Parafrasea citas; no las traduzcas palabra por palabra entre comillas.
        TXT;
    }

    public static function structureAndStyle(): string
    {
        return <<<'TXT'
        Objetivo editorial:
        - El lector debe entender qué se anunció, por qué importa y qué datos concretos hay (fechas, reparto, staff, plataformas).
        - Extensión objetivo: 500–800 palabras cuando el material lo permita; si la referencia es muy escasa, desarrolla lo disponible sin relleno (mínimo ~350 palabras útiles).

        Estructura sugerida (usa <h2> para cada bloque):
        1. Entradilla (2–3 frases con lo esencial: qué, cuándo, dónde).
        2. Qué se anunció (detalles concretos).
        3. Contexto de la obra o del anuncio (solo con datos de la referencia/investigación).
        4. Si hay datos sobre disponibilidad en español (doblaje, manga local, streaming), inclúyelos; si no hay, no inventes plataformas ni fechas.

        Prohibido en el texto:
        - "¿Qué opinas?", "¡Comparte en los comentarios!", "sin duda", "emocionante noticia", "en conclusión", "en el mundo del anime".
        - Cierres genéricos pidiendo opinión en comentarios.
        - Inventar cifras, nombres, plataformas o fechas no presentes en la referencia o investigación.
        TXT;
    }

    public static function researchInstructions(): string
    {
        return <<<'TXT'
        INSTRUCCIONES SOBRE LA INVESTIGACIÓN:
        - Usa la investigación solo para datos verificables (fechas extra, contexto de temporadas previas, confirmaciones en medios en español).
        - Integra los hechos en prosa; no copies extractos literales ni listes URLs en el cuerpo.
        - Si la investigación no aporta nada nuevo, no añadas un bloque vacío tipo "Contexto adicional"; escribe con lo de la referencia.
        - Si la investigación contradice la referencia scrapeada, prioriza la referencia principal.
        TXT;
    }
}
