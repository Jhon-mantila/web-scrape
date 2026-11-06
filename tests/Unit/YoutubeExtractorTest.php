<?php

namespace Tests\Unit;

use App\ProcessScraping\Support\YoutubeExtractor;
use PHPUnit\Framework\TestCase;

class YoutubeExtractorTest extends TestCase
{
    public function test_collect_includes_source_embed_from_html(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';

        $embeds = YoutubeExtractor::collect($html, null, null, 'Spy x Family Season 3', 'Announced today');

        $this->assertCount(1, $embeds);
        $this->assertStringContainsString('dQw4w9WgXcQ', $embeds[0]);
    }

    public function test_collect_ignores_unrelated_youtube_from_research_raw(): void
    {
        $researchRaw = [
            [
                'results' => [
                    [
                        'title' => 'Random cooking show episode 12',
                        'url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa',
                        'content' => 'Kitchen tips',
                    ],
                ],
            ],
        ];

        $embeds = YoutubeExtractor::collect(
            '<p>Sin video</p>',
            null,
            $researchRaw,
            'Spy x Family Season 3 Announced',
            'The spy family anime returns for a third season.',
        );

        $this->assertSame([], $embeds);
    }

    public function test_collect_includes_relevant_youtube_from_research_raw(): void
    {
        $researchRaw = [
            [
                'results' => [
                    [
                        'title' => 'Spy x Family Season 3 Official Trailer',
                        'url' => 'https://www.youtube.com/watch?v=bbbbbbbbbbb',
                        'content' => 'Trailer for the third season of Spy x Family.',
                    ],
                ],
            ],
        ];

        $embeds = YoutubeExtractor::collect(
            '<p>Sin video en ANN</p>',
            null,
            $researchRaw,
            'Spy x Family Season 3 Announced',
            'The spy family anime returns for a third season.',
        );

        $this->assertCount(1, $embeds);
        $this->assertStringContainsString('bbbbbbbbbbb', $embeds[0]);
    }

    public function test_source_embeds_are_listed_before_research(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/source11111"></iframe>';
        $researchRaw = [
            [
                'results' => [
                    [
                        'title' => 'Spy x Family Season 3 trailer',
                        'url' => 'https://www.youtube.com/watch?v=research222',
                        'content' => 'Spy x Family season 3',
                    ],
                ],
            ],
        ];

        $embeds = YoutubeExtractor::collect(
            $html,
            null,
            $researchRaw,
            'Spy x Family Season 3',
            'Spy x Family returns.',
        );

        $this->assertStringContainsString('source11111', $embeds[0]);
    }
}
