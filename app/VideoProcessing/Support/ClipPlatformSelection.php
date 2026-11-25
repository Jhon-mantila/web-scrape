<?php

namespace App\VideoProcessing\Support;

use App\Models\SocialVideo;
use App\SocialPublishing\Enums\Platform;

class ClipPlatformSelection
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{inherit_platforms: bool, clip_platforms: list<string>}
     */
    public static function fromInput(SocialVideo $parent, mixed $inherit, mixed $platforms): array
    {
        $inheritPlatforms = filter_var($inherit ?? true, FILTER_VALIDATE_BOOLEAN);
        $requested = self::normalizeKeys($platforms);
        $parentKeys = $parent->publications()
            ->pluck('platform')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();

        if ($inheritPlatforms) {
            $keys = $requested === null ? $parentKeys : array_values(array_intersect($requested, $parentKeys));
        } else {
            $keys = $requested ?? [];
        }

        return [
            'inherit_platforms' => $inheritPlatforms,
            'clip_platforms' => $keys,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{platform: string, caption_generated: ?string, caption_edited: ?string}>
     */
    public static function drafts(SocialVideo $parent, array $params): array
    {
        $selection = self::fromInput(
            $parent,
            $params['inherit_platforms'] ?? true,
            $params['clip_platforms'] ?? null,
        );
        $wanted = $selection['clip_platforms'];

        if ($selection['inherit_platforms']) {
            return $parent->publications()
                ->get()
                ->filter(fn ($publication): bool => in_array($publication->platform, $wanted, true))
                ->map(fn ($publication): array => [
                    'platform' => (string) $publication->platform,
                    'caption_generated' => $publication->caption_generated,
                    'caption_edited' => $publication->caption_edited,
                ])
                ->values()
                ->all();
        }

        return array_map(fn (string $platform): array => [
            'platform' => $platform,
            'caption_generated' => null,
            'caption_edited' => null,
        ], $wanted);
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeKeys(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            return [];
        }

        $allowed = Platform::values();
        $keys = [];

        foreach ($raw as $item) {
            $key = is_string($item) ? $item : '';
            if ($key !== '' && in_array($key, $allowed, true) && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
