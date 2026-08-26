<?php

namespace App\SocialPublishing\Prompts;

use App\SocialPublishing\Enums\Platform;

class SocialCaptionPrompt
{
    public static function system(Platform $platform): string
    {
        return match ($platform) {
            Platform::Youtube => 'Eres editor de YouTube para EsquinaAnime. Escribes títulos y descripciones atractivas en español para fans de anime.',
            Platform::FacebookEsquinaweb, Platform::FacebookEsquinagamers => 'Eres community manager de Facebook para una página de anime en español. Escribes posts cortos, directos y con gancho.',
            Platform::Tiktok => 'Eres creador de TikTok de anime. Escribes captions cortos con hashtags relevantes en español.',
        };
    }

    public static function user(string $videoTitle, Platform $platform, ?string $notes = null): string
    {
        $maxChars = (int) config("social.platforms.{$platform->value}.caption.max_chars", 500);
        $label = $platform->label();

        $blocks = [
            "Plataforma: {$label}",
            "Título del video: {$videoTitle}",
        ];

        if ($notes) {
            $blocks[] = "Notas adicionales: {$notes}";
        }

        $blocks[] = '';
        $blocks[] = match ($platform) {
            Platform::Youtube => <<<TXT
            Genera contenido para YouTube en JSON:
            {
              "title": "título SEO max 70 chars",
              "description": "descripción completa max {$maxChars} chars con párrafos, emojis moderados, CTA sutil"
            }
            TXT,
            Platform::Tiktok => <<<TXT
            Genera caption para TikTok en JSON:
            {
              "caption": "texto max {$maxChars} chars con hashtags de anime al final"
            }
            TXT,
            default => <<<TXT
            Genera post para Facebook en JSON:
            {
              "caption": "texto max {$maxChars} chars, 2-4 emojis max, pregunta al final para engagement"
            }
            TXT,
        };

        $blocks[] = 'Responde SOLO JSON válido parseable. Sin markdown.';

        return implode("\n", $blocks);
    }
}
