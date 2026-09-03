<?php

namespace App\SocialPublishing\Support;

class VideoFileSize
{
    public static function bytesFromPath(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $bytes = filesize($path);

        return $bytes === false ? null : $bytes;
    }

    public static function megabytes(?int $bytes): ?float
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        return round($bytes / (1024 * 1024), 2);
    }

    public static function label(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return 'desconocido';
        }

        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2, '.', '').' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, '.', '').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, '.', '').' KB';
        }

        return $bytes.' B';
    }
}
