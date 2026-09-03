<?php

namespace App\SocialPublishing\Platforms\Facebook;

use RuntimeException;

/**
 * Lee dimensiones básicas del MP4 sin ffmpeg (box tkhd).
 */
class FacebookVideoMetadata
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $durationSeconds = null,
    ) {}

    public static function fromPath(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el video para leer metadatos.');
        }

        try {
            $data = fread($handle, min(512 * 1024, filesize($path) ?: 512 * 1024));

            if ($data === false || $data === '') {
                throw new RuntimeException('No se pudieron leer metadatos del MP4.');
            }

            [$width, $height] = self::parseVideoDimensions($data);
            $duration = self::parseDuration($data);

            return new self($width, $height, $duration);
        } finally {
            fclose($handle);
        }
    }

    public static function tryFromPath(string $path): ?self
    {
        try {
            return self::fromPath($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isPortrait(): bool
    {
        return $this->width > 0 && $this->height > 0 && $this->height > $this->width;
    }

    /** @return 'reel'|'page_video' */
    public function contentType(): string
    {
        return $this->isPortrait() ? 'reel' : 'page_video';
    }

    public function contentTypeLabel(): string
    {
        return $this->isPortrait()
            ? 'Reel (vertical)'
            : 'Video de página (horizontal)';
    }

    public function dimensionsLabel(): string
    {
        if ($this->width <= 0 || $this->height <= 0) {
            return 'dimensiones desconocidas';
        }

        return "{$this->width}×{$this->height}";
    }

    /**
     * @return array{width: int, height: int, duration_seconds: ?float, content_type: string, content_type_label: string}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->durationSeconds,
            'content_type' => $this->contentType(),
            'content_type_label' => $this->contentTypeLabel(),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseVideoDimensions(string $data): array
    {
        return self::findBestTkhdDimensions($data, 0, strlen($data));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function findBestTkhdDimensions(string $data, int $start, int $end): array
    {
        $best = [0, 0];
        $offset = $start;

        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);

            if ($size < 8) {
                break;
            }

            $payloadStart = $offset + 8;
            $payloadEnd = min($end, $offset + $size);

            if ($type === 'tkhd') {
                $parsed = self::parseTkhd(substr($data, $payloadStart, $payloadEnd - $payloadStart));

                if ($parsed[0] > 0 && $parsed[1] > 0 && ($parsed[0] > $best[0] || $parsed[1] > $best[1])) {
                    $best = $parsed;
                }
            }

            if (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts'], true)) {
                $nested = self::findBestTkhdDimensions($data, $payloadStart, $payloadEnd);

                if ($nested[0] > 0 && $nested[1] > 0 && ($nested[0] > $best[0] || $nested[1] > $best[1])) {
                    $best = $nested;
                }
            }

            $offset += $size;
        }

        return $best;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseTkhd(string $payload): array
    {
        if ($payload === '') {
            return [0, 0];
        }

        $version = ord($payload[0]);

        if ($version === 0 && strlen($payload) >= 84) {
            $w = unpack('N', substr($payload, 76, 4))[1];
            $h = unpack('N', substr($payload, 80, 4))[1];

            return [(int) round($w / 65536), (int) round($h / 65536)];
        }

        if ($version === 1 && strlen($payload) >= 96) {
            $w = unpack('N', substr($payload, 88, 4))[1];
            $h = unpack('N', substr($payload, 92, 4))[1];

            return [(int) round($w / 65536), (int) round($h / 65536)];
        }

        return [0, 0];
    }

    private static function parseDuration(string $data): ?float
    {
        return self::findMvhdDuration($data, 0, strlen($data));
    }

    private static function findMvhdDuration(string $data, int $start, int $end): ?float
    {
        $offset = $start;

        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);

            if ($size < 8) {
                break;
            }

            $payloadStart = $offset + 8;
            $payloadEnd = min($end, $offset + $size);

            if ($type === 'mvhd') {
                $payload = substr($data, $payloadStart, $payloadEnd - $payloadStart);
                $version = ord($payload[0] ?? "\0");

                if ($version === 0 && strlen($payload) >= 20) {
                    $timescale = unpack('N', substr($payload, 12, 4))[1];
                    $duration = unpack('N', substr($payload, 16, 4))[1];

                    return $timescale > 0 ? round($duration / $timescale, 2) : null;
                }

                if ($version === 1 && strlen($payload) >= 36) {
                    $timescale = unpack('N', substr($payload, 20, 4))[1];
                    $high = unpack('N', substr($payload, 24, 4))[1];
                    $low = unpack('N', substr($payload, 28, 4))[1];
                    $duration = ($high << 32) + $low;

                    return $timescale > 0 ? round($duration / $timescale, 2) : null;
                }
            }

            if ($type === 'moov') {
                $nested = self::findMvhdDuration($data, $payloadStart, $payloadEnd);

                if ($nested !== null) {
                    return $nested;
                }
            }

            $offset += $size;
        }

        return null;
    }
}
