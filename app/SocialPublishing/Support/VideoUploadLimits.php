<?php

namespace App\SocialPublishing\Support;

class VideoUploadLimits
{
    public static function videoMaxMb(): int
    {
        return max(1, (int) config('social.upload.max_video_mb', 1024));
    }

    public static function thumbnailMaxMb(): int
    {
        return max(1, (int) config('social.upload.max_thumbnail_mb', 10));
    }

    public static function videoMaxKb(): int
    {
        return self::videoMaxMb() * 1024;
    }

    public static function thumbnailMaxKb(): int
    {
        return self::thumbnailMaxMb() * 1024;
    }

    /**
     * @return array{
     *     max_video_mb: int,
     *     max_video_bytes: int,
     *     max_video_label: string,
     *     max_thumbnail_mb: int,
     *     max_thumbnail_bytes: int,
     *     max_thumbnail_label: string,
     *     video_formats: string,
     *     thumbnail_formats: string
     * }
     */
    public static function forUi(): array
    {
        $videoMb = self::videoMaxMb();
        $thumbMb = self::thumbnailMaxMb();

        /** @var list<string> $videoMimes */
        $videoMimes = config('social.upload.video_mimes', ['mp4', 'mov', 'webm']);
        /** @var list<string> $thumbMimes */
        $thumbMimes = config('social.upload.thumbnail_mimes', ['jpg', 'jpeg', 'png', 'webp']);

        return [
            'max_video_mb' => $videoMb,
            'max_video_bytes' => $videoMb * 1024 * 1024,
            'max_video_label' => self::mbLabel($videoMb),
            'max_thumbnail_mb' => $thumbMb,
            'max_thumbnail_bytes' => $thumbMb * 1024 * 1024,
            'max_thumbnail_label' => self::mbLabel($thumbMb),
            'video_formats' => strtoupper(implode(', ', $videoMimes)),
            'thumbnail_formats' => strtoupper(implode(', ', array_unique($thumbMimes))),
        ];
    }

    public static function mbLabel(int $mb): string
    {
        if ($mb >= 1024 && $mb % 1024 === 0) {
            return (int) ($mb / 1024).' GB';
        }

        if ($mb >= 1024) {
            $gb = $mb / 1024;

            return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.').' GB';
        }

        return $mb.' MB';
    }
}
