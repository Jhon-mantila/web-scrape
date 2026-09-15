<?php

namespace App\SocialPublishing\Prompts;

use App\SocialPublishing\Enums\Platform;

class SocialArticleCaptionPrompt
{
    public static function system(Platform $platform): string
    {
        return match ($platform) {
            Platform::FacebookEsquinaweb => 'Eres community manager de Facebook para Esquina Web (anime, manga, cultura geek en español). Escribes posts cortos con gancho para compartir un artículo del blog.',
            Platform::FacebookEsquinagamers => 'Eres community manager de Facebook para Esquina Gamers (videojuegos, anime gaming, cultura gamer en español). Escribes posts cortos con gancho para compartir un artículo del blog.',
            Platform::Linkedin => 'Eres Jhon, creador de contenido sobre anime, gaming y entretenimiento digital en LinkedIn. Tono profesional, cercano y con criterio propio. Escribes en primera persona cuando encaja.',
            Platform::LinkedinJessika => 'Eres Jessika, creadora de contenido sobre anime, cultura geek y entretenimiento en LinkedIn. Tono profesional, cálido y directo, distinto al de Jhon. Escribes en primera persona.',
            default => 'Eres community manager en español. Escribes posts con gancho para compartir un artículo.',
        };
    }

    public static function user(
        string $title,
        ?string $excerpt,
        Platform $platform,
        string $siteLabel,
    ): string {
        $maxChars = (int) config("social.platforms.{$platform->value}.caption.max_chars", 500);

        $blocks = [
            "Sitio: {$siteLabel}",
            "Título del artículo: {$title}",
        ];

        if (is_string($excerpt) && trim($excerpt) !== '') {
            $blocks[] = "Extracto del artículo: {$excerpt}";
        }

        $blocks[] = '';
        $blocks[] = match ($platform) {
            Platform::Linkedin, Platform::LinkedinJessika => <<<TXT
            Genera un post NUEVO para LinkedIn que acompañe el enlace al artículo. No copies el extracto tal cual.
            Máximo {$maxChars} caracteres. 1-2 emojis como mucho. Párrafos cortos. Cierra con una reflexión o pregunta sutil.
            Responde SOLO JSON válido:
            {
              "caption": "texto del post"
            }
            TXT,
            default => <<<TXT
            Genera un post NUEVO para Facebook que acompañe el enlace al artículo. No copies el extracto tal cual: reescribe con otro ángulo, más gancho y natural para redes.
            Máximo {$maxChars} caracteres. 2-4 emojis como mucho. Termina con una pregunta breve para engagement.
            Responde SOLO JSON válido:
            {
              "caption": "texto del post"
            }
            TXT,
        };
        $blocks[] = 'Sin markdown. Solo JSON.';

        return implode("\n", $blocks);
    }
}
