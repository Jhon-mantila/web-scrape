<?php

namespace Tests\Unit;

use App\SendWordpress\Support\WordpressCategoryTranslator;
use PHPUnit\Framework\TestCase;

class WordpressCategoryTranslatorTest extends TestCase
{
    public function test_translates_ann_category_slug(): void
    {
        $this->assertSame(
            'Videojuegos',
            WordpressCategoryTranslator::forNews('games', 'anime_news'),
        );
    }

    public function test_keeps_unknown_source_category(): void
    {
        $this->assertSame(
            'Opinión',
            WordpressCategoryTranslator::forNews('Opinión', 'esquinaweb'),
        );
    }
}
