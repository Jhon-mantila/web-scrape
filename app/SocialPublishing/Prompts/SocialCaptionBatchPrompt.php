<?php

namespace App\SocialPublishing\Prompts;

use App\SocialPublishing\Enums\Platform;

class SocialCaptionBatchPrompt
{
    public static function system(): string
    {
        return 'Eres editor de contenido social para EsquinaAnime (anime en español). '
            .'Generas textos adaptados a cada red en una sola respuesta JSON: '
            .'YouTube (título + descripción), Facebook (posts cortos), LinkedIn (tono profesional cercano). '
            .'Todo en español.';
    }

    /**
     * @param  list<Platform>  $platforms
     */
    public static function user(string $videoTitle, ?string $notes, array $platforms): string
    {
        $blocks = [
            'Genera textos para TODAS las plataformas listadas abajo en un único JSON.',
            '',
            "Título del video: {$videoTitle}",
        ];

        if ($notes !== null && trim($notes) !== '') {
            $blocks[] = "Notas adicionales (contexto compartido): {$notes}";
        }

        $blocks[] = '';
        $blocks[] = 'Plataformas y reglas:';

        foreach ($platforms as $platform) {
            $blocks[] = self::platformRuleLine($platform);
        }

        $blocks[] = '';
        $blocks[] = 'Formato de respuesta (claves exactas, una por plataforma):';
        $blocks[] = '{';

        foreach ($platforms as $platform) {
            $key = $platform->value;
            $blocks[] = match ($platform) {
                Platform::Youtube => "  \"{$key}\": { \"title\": \"max 70 chars SEO\", \"description\": \"max "
                    .self::maxChars($platform).' chars\" },',
                default => "  \"{$key}\": { \"caption\": \"max ".self::maxChars($platform).' chars\" },',
            };
        }

        $blocks[] = '}';
        $blocks[] = '';
        $blocks[] = 'Responde SOLO JSON válido parseable. Sin markdown. Sin claves extra.';

        return implode("\n", $blocks);
    }

    private static function platformRuleLine(Platform $platform): string
    {
        $label = $platform->label();
        $max = self::maxChars($platform);

        $tone = match ($platform) {
            Platform::Youtube => 'título SEO + descripción con párrafos, emojis moderados, CTA sutil',
            Platform::FacebookEsquinaweb, Platform::FacebookEsquinagamers => 'post corto, directo, 2-4 emojis, pregunta al final',
            Platform::Linkedin, Platform::LinkedinJessika => 'tono profesional, 1-2 emojis, párrafos cortos, CTA sutil',
            Platform::Tiktok => 'caption corto con hashtags de anime',
        };

        return "- {$platform->value} ({$label}): max {$max} chars — {$tone}";
    }

    private static function maxChars(Platform $platform): int
    {
        return (int) config("social.platforms.{$platform->value}.caption.max_chars", 500);
    }
}
