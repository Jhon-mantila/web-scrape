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
            'homenaje' => [
                'falleció', 'fallecimiento', 'fallecio', 'murió', 'murio', 'in memoriam', ' descanse', 'rip ', 'deja este mundo', 'fallece a los',
                ' dies at ', ' dies ', ' passed away', ' death of ', ' obituary', ' in memoriam', ' mourn ', ' mourning ',
            ],
            'rumor' => [
                'filtración', 'filtracion', 'rumor', 'supuestamente', 'podría', 'podria', 'leak', 'filtrado', 'sin confirmar', 'se habla de',
                ' reportedly', ' unconfirmed', ' speculation', ' allegedly', ' rumor', ' rumours ', ' leaks ',
            ],
            'polemica' => [
                'polémica', 'polemica', 'cancelado', 'cancelada', 'críticas', 'criticas', 'controversia', 'escándalo', 'escandalo', 'denuncia',
                ' controversy', ' controversial', ' backlash ', ' criticized', ' criticised', ' scandal', ' accused ',
            ],
            'anuncio' => [
                'anuncia', 'anuncio', 'temporada', 'trailer', 'estreno', 'confirmado', 'confirmada', 'premiere', 'lanzará', 'lanzara', 'renovada', 'nueva película', 'nueva pelicula',
                ' announces ', ' announced ', ' reveal', ' revealed ', ' season ', ' premieres ', ' premiere ', ' renew', ' renewed ', ' launches ', ' debut ',
            ],
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
