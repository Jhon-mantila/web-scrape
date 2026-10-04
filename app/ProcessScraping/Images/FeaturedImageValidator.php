<?php

namespace App\ProcessScraping\Images;

use Illuminate\Support\Facades\Storage;

class FeaturedImageValidator
{
    public static function isValidRelativePath(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        if (! Storage::disk('public')->exists($relativePath)) {
            return false;
        }

        return self::isValidAbsolutePath(Storage::disk('public')->path($relativePath));
    }

    public static function isValidAbsolutePath(string $absolutePath): bool
    {
        if (! is_readable($absolutePath)) {
            return false;
        }

        $size = @filesize($absolutePath);

        if ($size === false || $size < 512) {
            return false;
        }

        $info = @getimagesize($absolutePath);

        if ($info !== false) {
            return in_array($info[2], [
                \IMAGETYPE_JPEG,
                \IMAGETYPE_PNG,
                \IMAGETYPE_WEBP,
                \IMAGETYPE_GIF,
            ], true);
        }

        if (! class_exists(\finfo::class)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        return is_string($mime)
            && str_starts_with($mime, 'image/')
            && ! str_contains($mime, 'svg');
    }

    public static function isValidImageBytes(string $bytes): bool
    {
        if (strlen($bytes) < 512) {
            return false;
        }

        if (@getimagesizefromstring($bytes) !== false) {
            return true;
        }

        if (! class_exists(\finfo::class)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime)
            && str_starts_with($mime, 'image/')
            && ! str_contains($mime, 'svg');
    }

    public static function guessExtensionFromBytes(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        return match ($info[2]) {
            \IMAGETYPE_PNG => 'png',
            \IMAGETYPE_WEBP => 'webp',
            \IMAGETYPE_GIF => 'gif',
            \IMAGETYPE_JPEG => 'jpg',
            default => null,
        };
    }

    public static function guessExtensionFromContentType(?string $contentType): string
    {
        $contentType = strtolower((string) $contentType);

        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };
    }
}
