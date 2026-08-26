<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\ProcessScraping\Ai\OllamaClient;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Prompts\SocialCaptionPrompt;

class GenerateSocialCaptionsAction
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    /**
     * @param  list<string>|null  $platforms
     */
    public function execute(SocialVideo $video, ?array $platforms = null): SocialVideo
    {
        $platforms ??= $video->publications()->pluck('platform')->all();

        foreach ($platforms as $platformKey) {
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

            $model = config("social.platforms.{$platformKey}.caption.model");

            $raw = $this->ollama->generate(
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

        return $video->fresh(['publications']);
    }

    private function parseCaption(string $raw, Platform $platform): string
    {
        $json = json_decode(trim($raw), true);

        if (is_array($json)) {
            return match ($platform) {
                Platform::Youtube => trim(($json['title'] ?? '')."\n\n".($json['description'] ?? '')),
                default => trim((string) ($json['caption'] ?? $json['description'] ?? $raw)),
            };
        }

        return trim($raw);
    }
}
