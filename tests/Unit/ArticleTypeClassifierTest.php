<?php

namespace Tests\Unit;

use App\ProcessScraping\Prompts\ArticleTypeClassifier;
use PHPUnit\Framework\TestCase;

class ArticleTypeClassifierTest extends TestCase
{
    public function test_classifies_english_obituary_as_homenaje(): void
    {
        $type = (new ArticleTypeClassifier)->classify(
            'Manga Creator Masako Watanabe Dies at 97',
            'The manga industry mourns the loss of Masako Watanabe.',
            'people',
        );

        $this->assertSame('homenaje', $type);
    }

    public function test_classifies_english_announcement_as_anuncio(): void
    {
        $type = (new ArticleTypeClassifier)->classify(
            'Crunchyroll Announces Season 2 Premiere Date',
            'The streaming service revealed the premiere window.',
            'anime',
        );

        $this->assertSame('anuncio', $type);
    }
}
