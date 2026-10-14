<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\SocialPublishing\Prompts\SocialCaptionBatchPrompt;
use App\SocialPublishing\Prompts\SocialCaptionPrompt;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;
use App\SocialPublishing\Support\VideoCaptionAiSettings;
use App\SocialPublishing\Support\VideoCaptionOllamaContext;
use Illuminate\Support\Facades\Log;

class GenerateSocialCaptionsAction
{
    public function __construct(
        private readonly VideoCaptionAiClientFactory $clientFactory,
    ) {}

    /**
     * @param  list<string>|null  $platforms
     */
    public function execute(SocialVideo $video, ?array $platforms = null): SocialVideo
    {
        $provider = $video->captionAiProvider();

        if ($provider === VideoCaptionAiProvider::None) {
            throw new \InvalidArgumentException('Este video está configurado sin IA para textos.');
        }

        VideoCaptionOllamaContext::applyFromVideo($video);

        try {
            $llm = $this->clientFactory->client($provider);
            $targets = $this->resolveTargets($video, $platforms);

            if ($targets === []) {
                return $video->fresh(['publications']);
            }

            if (VideoCaptionAiSettings::batchesCaptions($provider)) {
                $this->generateBatched($video, $targets, $llm, $provider);
            } else {
                $this->generatePerPlatform($video, $targets, $llm, $provider);
            }

            return $video->fresh(['publications']);
        } finally {
            VideoCaptionOllamaContext::reset();
        }
    }

    /**
     * @param  list<string>|null  $platformKeys
     * @return list<array{platform: Platform, publication: SocialPublication}>
     */
    private function resolveTargets(SocialVideo $video, ?array $platformKeys): array
    {
        $platformKeys ??= $video->publications()->pluck('platform')->all();
        $targets = [];

        foreach ($platformKeys as $platformKey) {
            $platform = Platform::from($platformKey);

            if (! $platform->isEnabled()) {
                continue;
            }

            if (config("social.platforms.{$platformKey}.coming_soon")) {
                continue;
            }

            /** @var SocialPublication $publication */
            $publication = $video->publications()->firstOrCreate(
                ['platform' => $platformKey],
                ['status' => PublicationStatus::Draft],
            );

            $targets[] = [
                'platform' => $platform,
                'publication' => $publication,
            ];
        }

        return $targets;
    }

    /**
     * @param  list<array{platform: Platform, publication: SocialPublication}>  $targets
     */
    private function generateBatched(
        SocialVideo $video,
        array $targets,
        \App\ProcessScraping\Ai\Contracts\TextGenerationClient $llm,
        VideoCaptionAiProvider $provider,
    ): void {
        $platforms = array_map(fn (array $row) => $row['platform'], $targets);

        $raw = $llm->generate(
            SocialCaptionBatchPrompt::system(),
            SocialCaptionBatchPrompt::user($video->title, $video->notes, $platforms),
            VideoCaptionAiSettings::modelForVideo($video, $provider),
        );

        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('La IA no devolvió JSON válido para captions en lote.');
        }

        foreach ($targets as $row) {
            $platform = $row['platform'];
            $publication = $row['publication'];
            $payload = $decoded[$platform->value] ?? null;

            if ($payload === null) {
                Log::warning('social_captions_batch: plataforma ausente en respuesta', [
                    'video_id' => $video->id,
                    'platform' => $platform->value,
                ]);

                continue;
            }

            $caption = $this->captionFromPayload($payload, $platform);

            if ($caption === '') {
                continue;
            }

            $publication->update([
                'caption_generated' => $caption,
                'status' => PublicationStatus::CaptionReady,
                'last_error' => null,
            ]);
        }
    }

    /**
     * @param  list<array{platform: Platform, publication: SocialPublication}>  $targets
     */
    private function generatePerPlatform(
        SocialVideo $video,
        array $targets,
        \App\ProcessScraping\Ai\Contracts\TextGenerationClient $llm,
        VideoCaptionAiProvider $provider,
    ): void {
        foreach ($targets as $row) {
            $platform = $row['platform'];
            $publication = $row['publication'];

            $model = VideoCaptionAiSettings::modelForVideoPlatform(
                $video,
                $provider,
                config("social.platforms.{$platform->value}.caption.model"),
            );

            $raw = $llm->generate(
                SocialCaptionPrompt::system($platform),
                SocialCaptionPrompt::user($video->title, $platform, $video->notes),
                $model,
            );

            $caption = $this->parseCaption($raw, $platform);

            $publication->update([
                'caption_generated' => $caption,
                'status' => PublicationStatus::CaptionReady,
                'last_error' => null,
            ]);
        }
    }

    private function parseCaption(string $raw, Platform $platform): string
    {
        $json = json_decode(trim($raw), true);

        if (is_array($json)) {
            return $this->captionFromPayload($json, $platform);
        }

        return trim($raw);
    }

    private function captionFromPayload(mixed $payload, Platform $platform): string
    {
        if (is_string($payload)) {
            return trim($payload);
        }

        if (! is_array($payload)) {
            return '';
        }

        return match ($platform) {
            Platform::Youtube => trim(($payload['title'] ?? '')."\n\n".($payload['description'] ?? '')),
            default => trim((string) ($payload['caption'] ?? $payload['description'] ?? '')),
        };
    }
}
