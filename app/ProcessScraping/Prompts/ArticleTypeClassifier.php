<?php

namespace App\ProcessScraping\Prompts;

class ArticleTypeClassifier
{
    /**
     * @return 'anuncio'|'rumor'|'polemica'|'homenaje'|'default'
     */
    public function classify(string $title, string $content, ?string $category = null): string
    {
        $text = mb_strtolower($title.' '.$content.' '.($category ?? ''));

        $rules = [
            'homenaje' => ['falleció', 'fallecimiento', 'fallecio', 'murió', 'murio', 'in memoriam', ' descanse', 'rip ', 'deja este mundo', 'fallece a los'],
            'rumor' => ['filtración', 'filtracion', 'rumor', 'supuestamente', 'podría', 'podria', 'leak', 'filtrado', 'sin confirmar', 'se habla de'],
            'polemica' => ['polémica', 'polemica', 'cancelado', 'cancelada', 'críticas', 'criticas', 'controversia', 'escándalo', 'escandalo', 'denuncia'],
            'anuncio' => ['anuncia', 'anuncio', 'temporada', 'trailer', 'estreno', 'confirmado', 'confirmada', 'premiere', 'lanzará', 'lanzara', 'renovada', 'nueva película', 'nueva pelicula'],
        ];

        foreach ($rules as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $type;
                }
            }
        }

        return 'default';
    }
}
