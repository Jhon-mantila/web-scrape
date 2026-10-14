<?php

namespace App\SendWordpress\Support;

class WordpressCategoryTranslator
{
    /** @var array<string, string> */
    private const ANN_MAP = [
        'anime' => 'Anime',
        'manga' => 'Manga',
        'games' => 'Videojuegos',
        'game' => 'Videojuegos',
        'korean' => 'Dorama y animación coreana',
        'people' => 'Personas',
        'industry' => 'Industria',
        'events' => 'Eventos',
        'music' => 'Música',
        'novels' => 'Novelas',
        'live-action' => 'Live action',
        'merchandise' => 'Merchandising',
        'reviews' => 'Reseñas',
        'interviews' => 'Entrevistas',
        'features' => 'Reportajes',
        'comics' => 'Cómics',
        'books' => 'Libros',
        'culture' => 'Cultura otaku',
        'general' => 'General',
    ];

    public static function forNews(?string $category, ?string $source): string
    {
        $name = trim((string) ($category ?? ''));

        if ($name === '') {
            return 'General';
        }

        if ($source === 'anime_news') {
            $key = mb_strtolower($name);

            if (isset(self::ANN_MAP[$key])) {
                return self::ANN_MAP[$key];
            }

            if (str_contains($key, ' ')) {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return $name;
    }
}
