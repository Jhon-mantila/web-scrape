<?php

namespace Tests\Unit\ProcessScraping;

use App\ProcessScraping\Images\Contracts\FeaturedImageUpscaleRunner;
use App\ProcessScraping\Images\FeaturedImageUpscaler;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeaturedImageUpscalerTest extends TestCase
{
    public function test_skips_when_disabled(): void
    {
        Storage::fake('public');
        config(['services.featured_image.upscale_enabled' => false]);

        $path = 'featured-images/1.jpg';
        Storage::disk('public')->put($path, $this->jpegBytes(80, 60));

        $upscaler = new FeaturedImageUpscaler($this->failingRunner());

        $this->assertSame(['path' => $path, 'enhanced' => false], $upscaler->enhanceIfSmall($path));
    }

    public function test_skips_when_file_is_at_least_300kb(): void
    {
        Storage::fake('public');
        config([
            'services.featured_image.upscale_enabled' => true,
            'services.featured_image.upscale_max_bytes' => 307200,
        ]);

        $path = 'featured-images/2.jpg';
        Storage::disk('public')->put($path, str_repeat('A', 307200));

        $upscaler = new FeaturedImageUpscaler($this->failingRunner());

        $this->assertSame(['path' => $path, 'enhanced' => false], $upscaler->enhanceIfSmall($path));
    }

    public function test_replaces_small_image_when_runner_succeeds(): void
    {
        Storage::fake('public');
        config([
            'services.featured_image.upscale_enabled' => true,
            'services.featured_image.upscale_max_bytes' => 307200,
            'services.featured_image.upscale_model_path' => storage_path('app/private/realesrgan/missing.onnx'),
            'services.featured_image.upscale_model_url' => '',
            'services.featured_image.upscale_model_sha256' => '',
        ]);

        $modelDir = storage_path('app/private/realesrgan');
        if (! is_dir($modelDir)) {
            mkdir($modelDir, 0775, true);
        }
        $modelPath = $modelDir.'/test-model.onnx';
        file_put_contents($modelPath, 'fake-onnx');
        config(['services.featured_image.upscale_model_path' => $modelPath]);

        $path = 'featured-images/3.jpg';
        Storage::disk('public')->put($path, $this->jpegBytes(64, 48));

        $upscaler = new FeaturedImageUpscaler(new class implements FeaturedImageUpscaleRunner
        {
            public function run(string $inputPath, string $outputPath, string $modelPath, int $maxSide): array
            {
                $bigger = FeaturedImageUpscalerTest::jpegFixture(180, 120);
                file_put_contents($outputPath, $bigger);

                return ['ok' => true, 'error' => null];
            }
        });

        $result = $upscaler->enhanceIfSmall($path);

        $this->assertTrue($result['enhanced']);
        $this->assertSame($path, $result['path']);
        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertGreaterThan(512, Storage::disk('public')->size($path));

        @unlink($modelPath);
    }

    public function test_keeps_original_when_runner_fails(): void
    {
        Storage::fake('public');
        config([
            'services.featured_image.upscale_enabled' => true,
            'services.featured_image.upscale_max_bytes' => 307200,
            'services.featured_image.upscale_model_url' => '',
            'services.featured_image.upscale_model_sha256' => '',
        ]);

        $modelDir = storage_path('app/private/realesrgan');
        if (! is_dir($modelDir)) {
            mkdir($modelDir, 0775, true);
        }
        $modelPath = $modelDir.'/test-model.onnx';
        file_put_contents($modelPath, 'fake-onnx');
        config(['services.featured_image.upscale_model_path' => $modelPath]);

        $path = 'featured-images/4.jpg';
        $original = $this->jpegBytes(64, 48);
        Storage::disk('public')->put($path, $original);

        $upscaler = new FeaturedImageUpscaler($this->failingRunner());

        $this->assertSame(['path' => $path, 'enhanced' => false], $upscaler->enhanceIfSmall($path));
        $this->assertSame($original, Storage::disk('public')->get($path));

        @unlink($modelPath);
    }

    public function test_skips_when_longest_side_already_meets_max(): void
    {
        Storage::fake('public');
        config([
            'services.featured_image.upscale_enabled' => true,
            'services.featured_image.upscale_max_bytes' => 307200,
            'services.featured_image.upscale_max_side' => 100,
        ]);

        $path = 'featured-images/5.jpg';
        Storage::disk('public')->put($path, $this->jpegBytes(160, 80));

        $upscaler = new FeaturedImageUpscaler($this->failingRunner());

        $this->assertSame(['path' => $path, 'enhanced' => false], $upscaler->enhanceIfSmall($path));
    }

    public function test_enhance_until_ready_runs_a_second_pass_while_still_small(): void
    {
        Storage::fake('public');
        config([
            'services.featured_image.upscale_enabled' => true,
            'services.featured_image.upscale_max_bytes' => 307200,
            'services.featured_image.upscale_max_side' => 2560,
            'services.featured_image.upscale_model_url' => '',
            'services.featured_image.upscale_model_sha256' => '',
        ]);

        $modelDir = storage_path('app/private/realesrgan');
        if (! is_dir($modelDir)) {
            mkdir($modelDir, 0775, true);
        }
        $modelPath = $modelDir.'/test-model.onnx';
        file_put_contents($modelPath, 'fake-onnx');
        config(['services.featured_image.upscale_model_path' => $modelPath]);

        $path = 'featured-images/6.jpg';
        Storage::disk('public')->put($path, $this->jpegBytes(48, 32));

        $upscaler = new FeaturedImageUpscaler(new class implements FeaturedImageUpscaleRunner
        {
            public int $runs = 0;

            public function run(string $inputPath, string $outputPath, string $modelPath, int $maxSide): array
            {
                $this->runs++;
                $size = $this->runs === 1 ? [96, 64] : [220, 140];
                file_put_contents($outputPath, FeaturedImageUpscalerTest::jpegFixture($size[0], $size[1]));

                return ['ok' => true, 'error' => null];
            }
        });

        $result = $upscaler->enhanceUntilReady($path);

        $this->assertTrue($result['enhanced']);
        $this->assertSame($path, $result['path']);
        $info = getimagesize(Storage::disk('public')->path($path));
        $this->assertNotFalse($info);
        $this->assertSame(220, $info[0]);
        $this->assertSame(140, $info[1]);

        @unlink($modelPath);
    }

    public static function jpegFixture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 80, 160));
        ob_start();
        imagejpeg($image, null, 70);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function jpegBytes(int $width, int $height): string
    {
        return self::jpegFixture($width, $height);
    }

    private function failingRunner(): FeaturedImageUpscaleRunner
    {
        return new class implements FeaturedImageUpscaleRunner
        {
            public function run(string $inputPath, string $outputPath, string $modelPath, int $maxSide): array
            {
                return ['ok' => false, 'error' => 'forced'];
            }
        };
    }
}
