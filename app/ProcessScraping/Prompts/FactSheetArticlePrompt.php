<?php

namespace App\ProcessScraping\Prompts;

class FactSheetArticlePrompt
{
    public static function system(?string $articleType): string
    {
        return PromptLibrary::system($articleType ?? 'default');
    }

    public static function user(string $factSheetJson, ?string $articleType): string
    {
        $type = $articleType ?? 'default';
        $tone = PromptLibrary::userExtra($type);

        return <<<TXT
        Eres redactor de EsquinaAnime (Latinoamérica y España).
        Escribe en español neutro usando EXCLUSIVAMENTE la FICHA JSON.
        Si un dato no está en FICHA, no lo menciones ni lo supongas.

        TIPO DE ARTÍCULO: {$type}
        {$tone}

        Estructura HTML (sin <h1>):
        - Entradilla en <p> (2-3 frases).
        - <h2>Qué se anunció</h2>
        - <h2>Contexto de la obra</h2> (solo si FICHA trae contexto_obra o hechos relevantes)
        - <h2>¿Dónde verlo o leerlo en español?</h2> (usa disponibilidad_es; si vacío, indica que no está confirmado)
        - <h2>Nuestra lectura</h2> (1 párrafo de análisis moderado, sin exagerar)

        Prohibido: "¿Qué opinas?", "¡Comparte en los comentarios!", "sin duda", "emocionante", "en conclusión", "en el mundo del anime".

        Extensión: mínimo 350 palabras en el HTML (ideal 600-900) si FICHA.estado es OK; desarrolla cada <h2> con varios párrafos.
        Si no hay material para 350 palabras honestas, devuelve estado INSUFICIENTE (no rellenes).

        FICHA:
        {$factSheetJson}

        JSON de salida (una línea por campo html escapado):
        {
          "titulo": "SEO ≤70 caracteres",
          "meta_descripcion": "≤155 caracteres",
          "slug": "slug-url-amigable",
          "html": "HTML en una sola línea",
          "etiquetas": ["franquicia", "estudio"],
          "estado": "OK|INSUFICIENTE"
        }
        TXT;
    }
}
