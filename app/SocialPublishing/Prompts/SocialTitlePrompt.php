<?php

namespace App\SocialPublishing\Prompts;

class SocialTitlePrompt
{
    public static function system(): string
    {
        return 'Eres editor de títulos para videos de anime en español (EsquinaAnime). '
            .'Escribes títulos cortos, claros y con gancho para clics. Sin clickbait falso.';
    }

    public static function user(string $currentTitle, ?string $notes = null): string
    {
        $blocks = [
            'Título actual (puede mejorarse):',
            $currentTitle,
        ];

        if ($notes) {
            $blocks[] = '';
            $blocks[] = 'Contexto del video:';
            $blocks[] = $notes;
        }

        $blocks[] = '';
        $blocks[] = <<<'TXT'
        Genera UN título mejor en español para este video de anime.
        - Máximo 70 caracteres
        - Directo y atractivo
        - Sin comillas ni emojis excesivos

        Responde SOLO JSON válido:
        {"title": "string"}
        TXT;

        return implode("\n", $blocks);
    }
}
