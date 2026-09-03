<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Prepara MP4 para la API de video de Facebook (H.264 + AAC + 30 fps).
 * Meta suele rechazar en procesamiento (1363008) videos a 60 fps u otros perfiles raros.
 */
class FacebookVideoPreparer
{
    public function isEnabled(): bool
    {
        return (bool) config('social.facebook.normalize_video', true);
    }

    public function prepare(string $videoPath): string
    {
        if (! $this->isEnabled()) {
            return $videoPath;
        }

        if (! is_file($videoPath)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $ffmpeg = $this->ffmpegBinary();

        if ($ffmpeg === null) {
            Log::warning('facebook: ffmpeg no disponible, se sube el video sin normalizar');

            return $videoPath;
        }

        $output = tempnam(sys_get_temp_dir(), 'fb-video-');

        if ($output === false) {
            return $videoPath;
        }

        $outputPath = $output.'.mp4';
        @unlink($output);

        $input = escapeshellarg($videoPath);
        $out = escapeshellarg($outputPath);
        $ffmpegBin = escapeshellarg($ffmpeg);

        $command = "{$ffmpegBin} -y -i {$input} "
            .'-c:v libx264 -profile:v high -pix_fmt yuv420p -preset fast -crf 23 -r 30 '
            .'-c:a aac -b:a 128k -ar 44100 -ac 2 '
            .'-movflags +faststart '
            ."-vf \"scale='min(1920,iw)':-2\" "
            ."{$out} 2>&1";

        exec($command, $lines, $exitCode);

        if ($exitCode !== 0 || ! is_file($outputPath) || filesize($outputPath) <= 0) {
            @unlink($outputPath);
            Log::warning('facebook: normalización ffmpeg falló', [
                'exit_code' => $exitCode,
                'output' => implode("\n", array_slice($lines, -15)),
            ]);

            return $videoPath;
        }

        Log::info('facebook: video normalizado para Meta', [
            'original_bytes' => filesize($videoPath),
            'output_bytes' => filesize($outputPath),
        ]);

        return $outputPath;
    }

    public function cleanup(string $originalPath, string $preparedPath): void
    {
        if ($preparedPath !== $originalPath && is_file($preparedPath)) {
            @unlink($preparedPath);
        }
    }

    private function ffmpegBinary(): ?string
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        return $ffmpeg !== '' ? $ffmpeg : null;
    }
}
