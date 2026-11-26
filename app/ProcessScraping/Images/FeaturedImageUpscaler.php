<?php

namespace App\ProcessScraping\Images;

use App\ProcessScraping\Images\Contracts\FeaturedImageUpscaleRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FeaturedImageUpscaler
{
    public function __construct(
        private readonly FeaturedImageUpscaleRunner $runner,
    ) {}

    /**
     * Mejora imágenes scrapeadas pequeñas con Real-ESRGAN ligero (4×).
     *
     * @return array{path: string, enhanced: bool}
     */
    public function enhanceIfSmall(string $relativePath): array
    {
        if (! config('services.featured_image.upscale_enabled')) {
            return $this->unchanged($relativePath);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            return $this->unchanged($relativePath);
        }

        $absolute = $disk->path($relativePath);
        $size = @filesize($absolute);

        if ($size === false || $size < 512) {
            return $this->unchanged($relativePath);
        }

        $maxBytes = (int) config('services.featured_image.upscale_max_bytes', 307200);

        if ($size >= $maxBytes) {
            Log::info('featured_image: upscale omitido (archivo >= umbral)', [
                'path' => $relativePath,
                'bytes' => $size,
                'max_bytes' => $maxBytes,
            ]);

            return $this->unchanged($relativePath);
        }

        $maxSide = max(512, (int) config('services.featured_image.upscale_max_side', 2560));
        $info = @getimagesize($absolute);

        if (is_array($info) && isset($info[0], $info[1]) && max((int) $info[0], (int) $info[1]) >= $maxSide) {
            Log::info('featured_image: upscale omitido (lado >= máximo)', [
                'path' => $relativePath,
                'width' => $info[0],
                'height' => $info[1],
                'max_side' => $maxSide,
            ]);

            return $this->unchanged($relativePath);
        }

        $modelPath = $this->ensureModel();

        if ($modelPath === null) {
            return $this->unchanged($relativePath);
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION) ?: 'png');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'png';
        }

        $tempRelative = $relativePath.'.upscaled.'.$extension;
        $tempAbsolute = $disk->path($tempRelative);

        try {
            $result = $this->runner->run(
                $absolute,
                $tempAbsolute,
                $modelPath,
                $maxSide,
            );

            if (! $result['ok'] || ! FeaturedImageValidator::isValidAbsolutePath($tempAbsolute)) {
                $disk->delete($tempRelative);

                return $this->unchanged($relativePath);
            }

            $finalRelative = $this->replaceOriginal($relativePath, $tempRelative, $extension);

            Log::info('featured_image: upscale realesr-general-x4v3 aplicado', [
                'path' => $finalRelative,
                'bytes_in' => $size,
                'bytes_out' => @filesize($disk->path($finalRelative)) ?: null,
            ]);

            return ['path' => $finalRelative, 'enhanced' => true];
        } catch (Throwable $e) {
            $disk->delete($tempRelative);
            Log::warning('featured_image: upscale excepción', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return $this->unchanged($relativePath);
        }
    }

    /**
     * Repite Real-ESRGAN (máx. 2 pases 4×) mientras el archivo siga pequeño y por debajo del lado máximo.
     *
     * @return array{path: string, enhanced: bool}
     */
    public function enhanceUntilReady(string $relativePath): array
    {
        $path = $relativePath;
        $enhanced = false;

        for ($pass = 0; $pass < 2; $pass++) {
            $result = $this->enhanceIfSmall($path);

            if (! $result['enhanced']) {
                break;
            }

            $enhanced = true;
            $path = $result['path'];
        }

        return ['path' => $path, 'enhanced' => $enhanced];
    }

    /**
     * @return array{path: string, enhanced: bool}
     */
    private function unchanged(string $relativePath): array
    {
        return ['path' => $relativePath, 'enhanced' => false];
    }

    private function replaceOriginal(string $originalRelative, string $tempRelative, string $extension): string
    {
        $disk = Storage::disk('public');
        $info = pathinfo($originalRelative);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $finalRelative = $dir.($info['filename'] ?? 'featured').'.'.$extension;

        if ($disk->exists($finalRelative) && $finalRelative !== $tempRelative) {
            $disk->delete($finalRelative);
        }

        $disk->move($tempRelative, $finalRelative);

        if ($originalRelative !== $finalRelative && $disk->exists($originalRelative)) {
            $disk->delete($originalRelative);
        }

        return $finalRelative;
    }

    public function ensureModel(): ?string
    {
        $path = (string) config(
            'services.featured_image.upscale_model_path',
            storage_path('app/private/realesrgan/realesr-general-x4v3.onnx'),
        );

        if (is_file($path) && $this->modelChecksumOk($path)) {
            return $path;
        }

        $url = (string) config('services.featured_image.upscale_model_url', '');

        if ($url === '') {
            Log::warning('featured_image: modelo realesrgan ausente', ['path' => $path]);

            return is_file($path) ? $path : null;
        }

        try {
            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $response = Http::timeout(120)
                ->withHeaders(['User-Agent' => 'EsquinaAI-FeaturedImageUpscaler/1.0'])
                ->get($url);

            if ($response->failed() || strlen($response->body()) < 1_000_000) {
                Log::warning('featured_image: no se pudo descargar modelo realesrgan', [
                    'status' => $response->status(),
                    'bytes' => strlen($response->body()),
                ]);

                return is_file($path) ? $path : null;
            }

            $tmp = $path.'.part';
            file_put_contents($tmp, $response->body());

            if (! $this->modelChecksumOk($tmp)) {
                @unlink($tmp);
                Log::warning('featured_image: checksum del modelo realesrgan no coincide');

                return is_file($path) ? $path : null;
            }

            rename($tmp, $path);

            return $path;
        } catch (Throwable $e) {
            Log::warning('featured_image: error descargando modelo realesrgan', [
                'error' => $e->getMessage(),
            ]);

            return is_file($path) ? $path : null;
        }
    }

    private function modelChecksumOk(string $path): bool
    {
        $expected = strtolower((string) config('services.featured_image.upscale_model_sha256', ''));

        if ($expected === '') {
            return true;
        }

        $actual = hash_file('sha256', $path);

        return is_string($actual) && hash_equals($expected, strtolower($actual));
    }
}
