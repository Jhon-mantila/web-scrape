<?php

namespace App\ProcessScraping\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FeaturedImageStorage
{
    public const DIRECTORY = 'featured-images';

    public static function ensureWritable(): bool
    {
        $disk = Storage::disk('public');

        if (! $disk->exists(self::DIRECTORY)) {
            $disk->makeDirectory(self::DIRECTORY);
        }

        $absolute = $disk->path(self::DIRECTORY);

        if (is_writable($absolute)) {
            return true;
        }

        Log::error('featured_image: directorio no escribible', [
            'path' => $absolute,
            'owner_uid' => @fileowner($absolute),
            'process_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'hint' => 'Permisos: el proceso web debe poder escribir en featured-images (Apache como UID 1000 o chown). '
                .'docker exec -u root laravel_app chown -R 1000:1000 storage/app/public/featured-images && '
                .'docker compose up -d --force-recreate laravel_app',
        ]);

        return false;
    }
}
