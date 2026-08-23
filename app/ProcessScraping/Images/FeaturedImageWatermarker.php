<?php

namespace App\ProcessScraping\Images;

use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FeaturedImageWatermarker
{
    /**
     * Aplica marca de agua y normaliza el formato de salida (webp por defecto).
     *
     * @return string|null Ruta relativa final en disco, o null si falló
     */
    public function apply(string $relativePath): ?string
    {
        if (! config('services.featured_image.watermark_enabled')) {
            return $this->normalizeOnly($relativePath);
        }

        $logoPath = (string) config('services.featured_image.watermark_path');

        if ($logoPath === '' || ! is_readable($logoPath)) {
            Log::warning('featured_image: logo de marca de agua no encontrado', ['path' => $logoPath]);

            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            return null;
        }

        $imagePath = $disk->path($relativePath);

        try {
            $main = $this->loadMainImage($imagePath);

            if ($main === null) {
                Log::warning('featured_image: no se pudo cargar imagen para marca de agua', [
                    'path' => $relativePath,
                    'webp_read' => \function_exists('imagecreatefromwebp'),
                    'webp_write' => \function_exists('imagewebp'),
                ]);

                return null;
            }

            $logo = $this->loadLogoImage($logoPath);

            if ($logo === null) {
                \imagedestroy($main);

                return null;
            }

            $mainWidth = \imagesx($main);
            $mainHeight = \imagesy($main);
            $logoWidth = \imagesx($logo);
            $logoHeight = \imagesy($logo);

            $targetLogoWidth = (int) max(
                1,
                round($mainWidth * ((float) config('services.featured_image.watermark_width_percent', 22) / 100))
            );
            $targetLogoHeight = (int) max(1, round($logoHeight * ($targetLogoWidth / $logoWidth)));

            $resizedLogo = \imagecreatetruecolor($targetLogoWidth, $targetLogoHeight);
            \imagealphablending($resizedLogo, false);
            \imagesavealpha($resizedLogo, true);
            $transparent = \imagecolorallocatealpha($resizedLogo, 0, 0, 0, 127);
            \imagefilledrectangle($resizedLogo, 0, 0, $targetLogoWidth, $targetLogoHeight, $transparent);
            \imagealphablending($resizedLogo, true);
            \imagesavealpha($resizedLogo, true);
            \imagecopyresampled(
                $resizedLogo,
                $logo,
                0,
                0,
                0,
                0,
                $targetLogoWidth,
                $targetLogoHeight,
                $logoWidth,
                $logoHeight,
            );
            \imagealphablending($resizedLogo, false);
            \imagesavealpha($resizedLogo, true);

            $position = (string) config('services.featured_image.watermark_position', 'bottom-left');
            $margin = (int) config('services.featured_image.watermark_margin', 24);
            $marginBottom = (int) config('services.featured_image.watermark_margin_bottom', 48);

            [$x, $y] = $this->position(
                $position,
                $mainWidth,
                $mainHeight,
                $targetLogoWidth,
                $targetLogoHeight,
                $margin,
                $marginBottom,
            );

            if (config('services.featured_image.watermark_mask_enabled')) {
                $this->applyDarkMask(
                    $main,
                    $x,
                    $y,
                    $targetLogoWidth,
                    $targetLogoHeight,
                    (int) config('services.featured_image.watermark_mask_padding', 14),
                    (int) config('services.featured_image.watermark_mask_opacity', 50),
                    str_starts_with($position, 'bottom'),
                    (bool) config('services.featured_image.watermark_mask_full_width', true),
                );
            }

            $this->copyWithAlpha(
                $main,
                $resizedLogo,
                $x,
                $y,
                (int) config('services.featured_image.watermark_opacity', 90),
            );

            $finalPath = $this->saveNormalized($main, $relativePath, $disk);

            \imagedestroy($main);
            \imagedestroy($logo);
            \imagedestroy($resizedLogo);

            return $finalPath;
        } catch (Throwable $e) {
            Log::warning('featured_image: fallo al aplicar marca de agua', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeOnly(string $relativePath): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            return null;
        }

        $main = $this->loadMainImage($disk->path($relativePath));

        if ($main === null) {
            return null;
        }

        $finalPath = $this->saveNormalized($main, $relativePath, $disk);
        \imagedestroy($main);

        return $finalPath;
    }

    private function loadMainImage(string $path): ?GdImage
    {
        $image = $this->decodeImage($path);

        if ($image === null) {
            return null;
        }

        return $this->flattenToOpaqueCanvas($image);
    }

    /** Logo PNG: conserva transparencia (sin fondo negro). */
    private function loadLogoImage(string $path): ?GdImage
    {
        $image = $this->decodeImage($path);

        if ($image === null) {
            return null;
        }

        \imagealphablending($image, false);
        \imagesavealpha($image, true);

        return $image;
    }

    private function decodeImage(string $path): ?GdImage
    {
        $info = @\getimagesize($path);

        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            \IMAGETYPE_JPEG => @\imagecreatefromjpeg($path) ?: null,
            \IMAGETYPE_PNG => @\imagecreatefrompng($path) ?: null,
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? (@\imagecreatefromwebp($path) ?: null) : null,
            \IMAGETYPE_GIF => @\imagecreatefromgif($path) ?: null,
            default => null,
        };

        if ($image instanceof GdImage && $info[2] === \IMAGETYPE_PNG) {
            \imagealphablending($image, false);
            \imagesavealpha($image, true);
        }

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * Foto principal en canvas opaco (solo la imagen base, no el logo).
     */
    private function flattenToOpaqueCanvas(GdImage $source): GdImage
    {
        $width = \imagesx($source);
        $height = \imagesy($source);
        $canvas = \imagecreatetruecolor($width, $height);

        $background = \imagecolorallocate($canvas, 0, 0, 0);
        \imagefilledrectangle($canvas, 0, 0, $width, $height, $background);
        \imagealphablending($canvas, true);
        \imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        \imagedestroy($source);

        return $canvas;
    }

    private function saveNormalized(GdImage $image, string $relativePath, $disk): ?string
    {
        $format = strtolower((string) config('services.featured_image.output_format', 'webp'));
        $finalRelativePath = $this->outputPathFor($relativePath, $format);
        $saved = $this->saveImage($image, $disk->path($finalRelativePath), $format);

        if (! $saved) {
            return null;
        }

        if ($finalRelativePath !== $relativePath && $disk->exists($relativePath)) {
            $disk->delete($relativePath);
        }

        return $finalRelativePath;
    }

    private function outputPathFor(string $relativePath, string $format): string
    {
        $format = match ($format) {
            'jpeg' => 'jpg',
            'jpg', 'png', 'webp' => $format,
            default => 'webp',
        };

        $directory = \dirname($relativePath);
        $filename = pathinfo($relativePath, PATHINFO_FILENAME);
        $prefix = $directory !== '.' ? $directory.'/' : '';

        return $prefix.$filename.'.'.$format;
    }

    private function saveImage(GdImage $image, string $path, string $format): bool
    {
        $quality = (int) config('services.featured_image.output_quality', 85);

        return match ($format) {
            'png' => \imagepng($image, $path),
            'webp' => \function_exists('imagewebp') ? \imagewebp($image, $path, max(1, min(100, $quality))) : false,
            'jpg', 'jpeg' => \imagejpeg($image, $path, max(1, min(100, $quality))),
            default => \function_exists('imagewebp')
                ? \imagewebp($image, $path, max(1, min(100, $quality)))
                : \imagejpeg($image, $path, max(1, min(100, $quality))),
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function position(
        string $position,
        int $mainWidth,
        int $mainHeight,
        int $logoWidth,
        int $logoHeight,
        int $margin,
        int $marginBottom,
    ): array {
        return match ($position) {
            'top-left' => [$margin, $margin],
            'top-right' => [$mainWidth - $logoWidth - $margin, $margin],
            'bottom-right' => [$mainWidth - $logoWidth - $margin, $mainHeight - $logoHeight - $marginBottom],
            default => [$margin, $mainHeight - $logoHeight - $marginBottom],
        };
    }

    private function applyDarkMask(
        GdImage $background,
        int $x,
        int $y,
        int $width,
        int $height,
        int $padding,
        int $opacityPercent,
        bool $flushBottom = false,
        bool $fullWidth = false,
    ): void {
        $bgWidth = \imagesx($background);
        $bgHeight = \imagesy($background);
        $opacityFactor = max(0, min(100, $opacityPercent)) / 100;

        if ($opacityFactor <= 0) {
            return;
        }

        $maskY = max(0, $y - $padding);
        $maskY2 = $flushBottom ? $bgHeight : min($bgHeight, $y + $height + $padding);
        $maskX = $fullWidth ? 0 : max(0, $x - $padding);
        $maskX2 = $fullWidth ? $bgWidth : min($bgWidth, $x + $width + $padding);

        \imagealphablending($background, true);

        for ($px = $maskX; $px < $maskX2; $px++) {
            for ($py = $maskY; $py < $maskY2; $py++) {
                $bgRgba = \imagecolorat($background, $px, $py);
                $br = ($bgRgba >> 16) & 0xFF;
                $bg = ($bgRgba >> 8) & 0xFF;
                $bb = $bgRgba & 0xFF;

                $nr = (int) round($br * (1 - $opacityFactor));
                $ng = (int) round($bg * (1 - $opacityFactor));
                $nb = (int) round($bb * (1 - $opacityFactor));

                $color = \imagecolorallocate($background, $nr, $ng, $nb);
                \imagesetpixel($background, $px, $py, $color);
            }
        }
    }

    private function copyWithAlpha(GdImage $background, GdImage $overlay, int $x, int $y, int $opacity): void
    {
        $overlayWidth = \imagesx($overlay);
        $overlayHeight = \imagesy($overlay);
        $bgWidth = \imagesx($background);
        $bgHeight = \imagesy($background);
        $opacityFactor = max(0, min(100, $opacity)) / 100;

        \imagealphablending($background, true);

        for ($ox = 0; $ox < $overlayWidth; $ox++) {
            for ($oy = 0; $oy < $overlayHeight; $oy++) {
                $bx = $x + $ox;
                $by = $y + $oy;

                if ($bx < 0 || $by < 0 || $bx >= $bgWidth || $by >= $bgHeight) {
                    continue;
                }

                $rgba = \imagecolorat($overlay, $ox, $oy);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha === 127) {
                    continue;
                }

                $sr = ($rgba >> 16) & 0xFF;
                $sg = ($rgba >> 8) & 0xFF;
                $sb = $rgba & 0xFF;

                $pixelAlpha = (127 - $alpha) / 127 * $opacityFactor;

                if ($pixelAlpha <= 0) {
                    continue;
                }

                $bgRgba = \imagecolorat($background, $bx, $by);
                $br = ($bgRgba >> 16) & 0xFF;
                $bg = ($bgRgba >> 8) & 0xFF;
                $bb = $bgRgba & 0xFF;

                $nr = (int) round($sr * $pixelAlpha + $br * (1 - $pixelAlpha));
                $ng = (int) round($sg * $pixelAlpha + $bg * (1 - $pixelAlpha));
                $nb = (int) round($sb * $pixelAlpha + $bb * (1 - $pixelAlpha));

                $color = \imagecolorallocate($background, $nr, $ng, $nb);
                \imagesetpixel($background, $bx, $by, $color);
            }
        }
    }
}
