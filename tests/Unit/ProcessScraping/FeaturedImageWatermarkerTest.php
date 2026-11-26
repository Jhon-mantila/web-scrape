<?php

namespace Tests\Unit\ProcessScraping;

use App\ProcessScraping\Images\FeaturedImageWatermarker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeaturedImageWatermarkerTest extends TestCase
{
    public function test_apply_draws_logo_on_bottom_of_image(): void
    {
        Storage::fake('public');

        $logoPath = storage_path('app/public/Logo/esquina_anime_logo.png');
        $this->assertFileExists($logoPath);

        config([
            'services.featured_image.watermark_enabled' => true,
            'services.featured_image.watermark_path' => $logoPath,
            'services.featured_image.watermark_position' => 'bottom-left',
            'services.featured_image.watermark_margin' => 8,
            'services.featured_image.watermark_margin_bottom' => 8,
            'services.featured_image.watermark_width_percent' => 30,
            'services.featured_image.watermark_mask_enabled' => true,
            'services.featured_image.output_format' => 'png',
        ]);

        $path = 'featured-images/watermark.jpg';
        Storage::disk('public')->put($path, FeaturedImageUpscalerTest::jpegFixture(400, 240));

        $final = (new FeaturedImageWatermarker())->apply($path);

        $this->assertNotNull($final);
        $this->assertTrue(Storage::disk('public')->exists($final));

        $image = imagecreatefrompng(Storage::disk('public')->path($final));
        $this->assertInstanceOf(\GdImage::class, $image);

        $width = imagesx($image);
        $height = imagesy($image);
        $bottom = imagecolorat($image, 20, $height - 12);
        $top = imagecolorat($image, (int) ($width / 2), 12);

        $this->assertNotSame($top, $bottom, 'La franja inferior con logo/máscara debe distinguirse del fondo.');

        imagedestroy($image);
    }
}
