<?php

namespace Tests\Unit;

use App\ProcessScraping\Support\ArticleQualityReviewer;
use Tests\TestCase;

class ArticleQualityReviewerTest extends TestCase
{
    public function test_rejects_insufficient_status(): void
    {
        $error = (new ArticleQualityReviewer)->validateHtml('<p>Texto</p>', 'INSUFICIENTE');

        $this->assertNotNull($error);
    }

    public function test_rejects_forbidden_phrase(): void
    {
        $html = '<p>'.str_repeat('palabra ', 400).' ¿Qué opinas de esto?</p>';
        $error = (new ArticleQualityReviewer)->validateHtml($html, 'OK');

        $this->assertNotNull($error);
    }
}
