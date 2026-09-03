<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookVideoInspector
{
    private const GRAPH_VERSION = 'v21.0';

    private const MAX_ATTEMPTS = 36;

    private const SLEEP_SECONDS = 10;

    /**
     * @param  'reel'|'page_video'|null  $expectedContentType
     * @return array{video_status: string, permalink_url: ?string, published: mixed, status: mixed, format: mixed}
     */
    public function waitForReady(
        string $videoId,
        string $accessToken,
        ?string $expectedContentType = null,
    ): array {
        if ($videoId === '') {
            throw new RuntimeException('No hay ID de video de Facebook para verificar.');
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::timeout(60)->get($this->graphUrl("/{$videoId}"), [
                'access_token' => $accessToken,
                'fields' => 'status,permalink_url,published,title,format',
            ]);

            FacebookGraphResponse::assertSuccessful($response, 'consultar estado del video');

            $status = $response->json('status');
            $format = $response->json('format');
            $videoStatus = is_array($status) ? ($status['video_status'] ?? null) : null;

            Log::info('facebook: video processing poll', [
                'video_id' => $videoId,
                'attempt' => $attempt,
                'video_status' => $videoStatus,
                'status' => $status,
                'format' => $format,
            ]);

            if ($videoStatus === 'ready') {
                return [
                    'video_status' => 'ready',
                    'permalink_url' => $response->json('permalink_url'),
                    'published' => $response->json('published'),
                    'status' => $status,
                    'format' => $format,
                ];
            }

            if (in_array($videoStatus, ['error', 'expired'], true)) {
                $reason = $this->extractStatusError(is_array($status) ? $status : null)
                    ?: $this->detectFormatIssue($format, $expectedContentType)
                    ?: $this->summarizeStatus(is_array($status) ? $status : null);

                throw new RuntimeException(
                    "Facebook rechazó el video: {$reason} Usa «Eliminar de Facebook» y vuelve a enviar.",
                );
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep(self::SLEEP_SECONDS);
            }
        }

        throw new RuntimeException(
            'Facebook no terminó de procesar el video (sigue en processing). '
            .'Elimínalo en Facebook e inténtalo de nuevo.',
        );
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function extractStatusError(?array $status): string
    {
        if ($status === null) {
            return '';
        }

        $messages = [];

        foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $phase) {
            if (! isset($status[$phase]) || ! is_array($status[$phase])) {
                continue;
            }

            $phaseData = $status[$phase];
            $phaseName = match ($phase) {
                'uploading_phase' => 'subida',
                'processing_phase' => 'procesamiento',
                'publishing_phase' => 'publicación',
                default => $phase,
            };

            if (isset($phaseData['error']) && is_array($phaseData['error'])) {
                $messages[] = $this->formatGraphError($phaseData['error'], $phaseName);
            }

            if (isset($phaseData['errors']) && is_array($phaseData['errors'])) {
                foreach ($phaseData['errors'] as $error) {
                    if (is_array($error)) {
                        $messages[] = $this->formatGraphError($error, $phaseName);
                    }
                }
            }

            if (($phaseData['status'] ?? null) === 'error') {
                $messages[] = "Error en fase de {$phaseName}.";
            }
        }

        return implode(' ', array_values(array_unique(array_filter($messages))));
    }

    /**
     * @param  'reel'|'page_video'|null  $expectedContentType
     */
    private function detectFormatIssue(mixed $format, ?string $expectedContentType = null): string
    {
        if (! is_array($format)) {
            return '';
        }

        foreach ($format as $item) {
            if (! is_array($item)) {
                continue;
            }

            $embed = (string) ($item['embed_html'] ?? '');
            $looksLikeReel = $this->embedLooksLikeReel($embed);

            if ($looksLikeReel && $expectedContentType === 'page_video') {
                return 'Meta lo clasificó como Reel aunque el video es horizontal. Inténtalo de nuevo; si persiste, revisa permisos de la app.';
            }

            if (! $looksLikeReel && $expectedContentType === 'reel') {
                return 'Meta no registró el contenido como Reel.';
            }

            $height = (int) ($item['height'] ?? 0);
            $width = (int) ($item['width'] ?? 0);

            if ($height === 0 || $width === 0) {
                return 'Meta no procesó el video (dimensiones 0×0 en el planificador).';
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function summarizeStatus(?array $status): string
    {
        if ($status === null) {
            return 'estado desconocido de Meta';
        }

        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || $encoded === '') {
            return 'estado desconocido de Meta';
        }

        return 'detalle Meta: '.mb_substr($encoded, 0, 280);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function formatGraphError(array $error, string $phaseName): string
    {
        $code = $error['code'] ?? null;
        $message = trim((string) ($error['message'] ?? ''));

        if ($code !== null && $message !== '') {
            return "[{$phaseName}] ({$code}) {$message}";
        }

        if ($message !== '') {
            return "[{$phaseName}] {$message}";
        }

        if ($code !== null) {
            return "[{$phaseName}] código {$code}.";
        }

        return "Error en fase de {$phaseName}.";
    }

    private function embedLooksLikeReel(string $embedHtml): bool
    {
        $decoded = urldecode($embedHtml);

        return str_contains($embedHtml, '/reel/')
            || str_contains($decoded, '/reel/');
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
