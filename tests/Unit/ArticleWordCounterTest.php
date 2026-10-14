<?php

namespace Tests\Unit;

use App\ProcessScraping\Support\ArticleWordCounter;
use PHPUnit\Framework\TestCase;

class ArticleWordCounterTest extends TestCase
{
    public function test_counts_words_from_html(): void
    {
        $html = '<p>Uno dos tres.</p><p>Cuatro cinco.</p>';

        $this->assertSame(5, ArticleWordCounter::fromHtml($html));
    }
}
