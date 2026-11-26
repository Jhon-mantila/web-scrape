<?php

namespace Tests\Unit\ProcessScraping;

use App\ProcessScraping\Support\PipelineSummaryFormatter;
use Tests\TestCase;

class PipelineSummaryFormatterTest extends TestCase
{
    public function test_images_detail_includes_upscaled_count(): void
    {
        $formatter = new PipelineSummaryFormatter();

        $this->assertSame(
            'Imágenes 1 desc. · 0 FLUX · 1 mejorada (Real-ESRGAN)',
            $formatter->formatImagesDetail([
                'downloaded' => 1,
                'generated' => 0,
                'upscaled' => 1,
            ]),
        );

        $this->assertSame(
            'Imágenes 0 desc. · 0 FLUX · 0 mejoradas (Real-ESRGAN)',
            $formatter->formatImagesDetail([
                'downloaded' => 0,
                'generated' => 0,
                'upscaled' => 0,
            ]),
        );
    }

    public function test_pipeline_message_includes_realesrgan_count(): void
    {
        $formatter = new PipelineSummaryFormatter();
        $message = $formatter->formatPipeline([
            'details' => ['success' => 1, 'processed' => 1],
            'images' => ['downloaded' => 0, 'generated' => 0, 'upscaled' => 2],
            'research' => ['success' => 1, 'processed' => 1],
            'ai' => ['success' => 1, 'processed' => 1, 'tokens_total' => 0],
            'wordpress' => ['success' => 0, 'processed' => 0],
            'timings' => [],
        ], [
            'skip_scrape' => true,
            'skip_research' => false,
            'send_wordpress' => false,
        ]);

        $this->assertStringContainsString('2 mejoradas (Real-ESRGAN)', $message);
        $this->assertStringContainsString('Imágenes 0 desc.', $message);
    }
}
