<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookVideoInspector
{
    private const GRAPH_VERSION = 'v21.0';

    private const VIDEO_FIELDS = 'status,permalink_url,published,title,format';

    private const MAX_ATTEMPTS = 36;

    private const SLEEP_SECONDS = 10;

    private const PUBLISH_VISIBILITY_MAX_ATTEMPTS = 12;

    private const PUBLISH_VISIBILITY_SLEEP_SECONDS = 5;

    /**
     * @return array{
     *     video_status: ?string,
     *     permalink_url: mixed,
     *     published: mixed,
     *     status: mixed,
     *     format: mixed,
     *     publish_status: ?string,
     *     embed_is_reel: bool
     * }
     */
    public function inspect(string $videoId, string $accessToken): array
    {
        if ($videoId === '') {
            throw new RuntimeException('No hay ID de video de Facebook para consultar.');
        }

        $response = Http::timeout(60)->get($this->graphUrl("/{$videoId}"), [
            'access_token' => $accessToken,
            'fields' => self::VIDEO_FIELDS,
        ]);

        FacebookGraphResponse::assertSuccessful($response, 'consultar video de Facebook');

        return $this->normalizeInspection(
            $response->json('status'),
            $response->json('published'),
            $response->json('permalink_url'),
            $response->json('format'),
        );
    }

    /**
     * @param  'reel'|'page_video'|null  $expectedContentType
     * @return array{
     *     video_status: string,
     *     permalink_url: mixed,
     *     published: mixed,
     *     status: mixed,
     *     format: mixed,
     *     publish_status: ?string,
     *     embed_is_reel: bool
     * }
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
                'fields' => self::VIDEO_FIELDS,
            ]);

            FacebookGraphResponse::assertSuccessful($response, 'consultar estado del video');

            $status = $response->json('status');
            $format = $response->json('format');
            $videoStatus = is_array($status) ? ($status['video_status'] ?? null) : null;
            $publishStatus = self::extractPublishStatus(is_array($status) ? $status : null);
            $embedIsReel = $this->formatEmbedIsReel($format);

            Log::info('facebook: video processing poll', [
                'video_id' => $videoId,
                'attempt' => $attempt,
                'video_status' => $videoStatus,
                'publish_status' => $publishStatus,
                'embed_is_reel' => $embedIsReel,
                'published' => $response->json('published'),
                'status' => $status,
                'format' => $format,
            ]);

            if ($videoStatus === 'ready') {
                return $this->normalizeInspection(
                    $status,
                    $response->json('published'),
                    $response->json('permalink_url'),
                    $format,
                );
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
     * @return array{
     *     video_status: ?string,
     *     permalink_url: mixed,
     *     published: mixed,
     *     status: mixed,
     *     format: mixed,
     *     publish_status: ?string,
     *     embed_is_reel: bool
     * }
     */
    public function waitForPublicPublish(string $videoId, string $accessToken): array
    {
        $lastInfo = null;

        for ($attempt = 1; $attempt <= self::PUBLISH_VISIBILITY_MAX_ATTEMPTS; $attempt++) {
            $info = $this->inspect($videoId, $accessToken);
            $lastInfo = $info;

            Log::info('facebook: video publish visibility poll', [
                'video_id' => $videoId,
                'attempt' => $attempt,
                'published' => $info['published'],
                'publish_status' => $info['publish_status'],
                'embed_is_reel' => $info['embed_is_reel'],
            ]);

            if ($this->isPubliclyPublished($info)) {
                return $info;
            }

            if ($attempt < self::PUBLISH_VISIBILITY_MAX_ATTEMPTS) {
                sleep(self::PUBLISH_VISIBILITY_SLEEP_SECONDS);
            }
        }

        $publishStatus = is_array($lastInfo) ? ($lastInfo['publish_status'] ?? 'desconocido') : 'desconocido';

        throw new RuntimeException(
            "Meta procesó el video pero no quedó público (publish_status={$publishStatus}). "
            .'Elimínalo en Facebook e inténtalo de nuevo.',
        );
    }

    /**
     * @return array{
     *     video_status: ?string,
     *     permalink_url: mixed,
     *     published: mixed,
     *     status: mixed,
     *     format: mixed,
     *     publish_status: ?string,
     *     embed_is_reel: bool
     * }
     */
    public function waitForScheduledOnMeta(string $videoId, string $accessToken): array
    {
        $lastInfo = null;

        for ($attempt = 1; $attempt <= self::PUBLISH_VISIBILITY_MAX_ATTEMPTS; $attempt++) {
            $info = $this->inspect($videoId, $accessToken);
            $lastInfo = $info;

            Log::info('facebook: video schedule visibility poll', [
                'video_id' => $videoId,
                'attempt' => $attempt,
                'published' => $info['published'],
                'publish_status' => $info['publish_status'],
                'embed_is_reel' => $info['embed_is_reel'],
            ]);

            if ($this->isScheduledOnMeta($info)) {
                return $info;
            }

            if ($attempt < self::PUBLISH_VISIBILITY_MAX_ATTEMPTS) {
                sleep(self::PUBLISH_VISIBILITY_SLEEP_SECONDS);
            }
        }

        $publishStatus = is_array($lastInfo) ? ($lastInfo['publish_status'] ?? 'desconocido') : 'desconocido';

        throw new RuntimeException(
            "Meta no confirmó la programación del video (publish_status={$publishStatus}). "
            .'Elimínalo en Facebook e inténtalo de nuevo.',
        );
    }

    /**
     * @param  array{
     *     video_status?: ?string,
     *     published?: mixed,
     *     publish_status?: ?string
     * }  $info
     */
    public function isPubliclyPublished(array $info): bool
    {
        if (! $this->isPublishedFlag($info['published'] ?? null)) {
            return false;
        }

        $publishStatus = $info['publish_status'] ?? null;

        if ($publishStatus === 'scheduled') {
            return false;
        }

        if ($publishStatus === 'published') {
            return true;
        }

        return ($info['video_status'] ?? null) === 'ready';
    }

    /**
     * @param  array{publish_status?: ?string}  $info
     */
    public function isScheduledOnMeta(array $info): bool
    {
        return ($info['publish_status'] ?? null) === 'scheduled';
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    public static function extractPublishStatus(?array $status): ?string
    {
        if ($status === null || ! isset($status['publishing_phase']) || ! is_array($status['publishing_phase'])) {
            return null;
        }

        $publishStatus = $status['publishing_phase']['publish_status'] ?? null;

        return is_string($publishStatus) ? $publishStatus : null;
    }

    /**
     * @return array{
     *     video_status: ?string,
     *     permalink_url: mixed,
     *     published: mixed,
     *     status: mixed,
     *     format: mixed,
     *     publish_status: ?string,
     *     embed_is_reel: bool
     * }
     */
    private function normalizeInspection(mixed $status, mixed $published, mixed $permalink, mixed $format): array
    {
        $videoStatus = is_array($status) ? ($status['video_status'] ?? null) : null;

        return [
            'video_status' => is_string($videoStatus) ? $videoStatus : null,
            'permalink_url' => $permalink,
            'published' => $published,
            'status' => $status,
            'format' => $format,
            'publish_status' => self::extractPublishStatus(is_array($status) ? $status : null),
            'embed_is_reel' => $this->formatEmbedIsReel($format),
        ];
    }

    private function isPublishedFlag(mixed $published): bool
    {
        return $published === true || $published === 1 || $published === 'true';
    }

    private function formatEmbedIsReel(mixed $format): bool
    {
        if (! is_array($format)) {
            return false;
        }

        foreach ($format as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($this->embedLooksLikeReel((string) ($item['embed_html'] ?? ''))) {
                return true;
            }
        }

        return false;
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
