<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\SocialPublishing\Prompts\SocialTitlePrompt;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;
use App\SocialPublishing\Support\VideoCaptionAiSettings;
use App\SocialPublishing\Support\VideoCaptionOllamaContext;

class GenerateSocialTitleAction
{
    public function __construct(
        private readonly VideoCaptionAiClientFactory $clientFactory,
    ) {}

    public function execute(SocialVideo $video): SocialVideo
    {
        $provider = $video->captionAiProvider();

        if ($provider === VideoCaptionAiProvider::None) {
            throw new \InvalidArgumentException('Este video está configurado sin IA para textos.');
        }

        VideoCaptionOllamaContext::applyFromVideo($video);

        try {
            $llm = $this->clientFactory->client($provider);
            $model = VideoCaptionAiSettings::modelForVideo($video, $provider);

            $raw = $llm->generate(
                SocialTitlePrompt::system(),
                SocialTitlePrompt::user($video->title, $video->notes),
                $model,
            );

            $title = $this->parseTitle($raw) ?? trim($raw);

            if ($title !== '') {
                $video->update(['title' => mb_substr($title, 0, 255)]);
            }

            return $video->fresh();
        } finally {
            VideoCaptionOllamaContext::reset();
        }
    }

    private function parseTitle(string $raw): ?string
    {
        $json = json_decode(trim($raw), true);

        if (is_array($json) && ! empty($json['title'])) {
            return trim((string) $json['title']);
        }

        return null;
    }
}
