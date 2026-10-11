<?php

namespace App\SocialPublishing\Actions;

use App\Models\WordpressPost;
use App\ProcessScraping\Ai\Support\AiSettings;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\SocialPublishing\Prompts\SocialArticleCaptionPrompt;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;

class GenerateSocialArticleCaptionAction
{
    public function __construct(
        private readonly VideoCaptionAiClientFactory $clientFactory,
    ) {}

    /**
     * @param  list<string>  $platformKeys
     * @return array<string, string>
     */
    public function executeMany(
        WordpressPost $post,
        array $platformKeys,
        VideoCaptionAiProvider|string|null $provider = null,
    ): array {
        $captions = [];

        foreach ($platformKeys as $platformKey) {
            $captions[$platformKey] = $this->execute($post, $platformKey, $provider);
        }

        return $captions;
    }

    public function execute(
        WordpressPost $post,
        string $platformKey,
        VideoCaptionAiProvider|string|null $provider = null,
    ): string {
        $providerEnum = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored(is_string($provider) ? $provider : null);

        if ($providerEnum === VideoCaptionAiProvider::None) {
            throw new \InvalidArgumentException('Selecciona Ollama local o DeepSeek para generar texto.');
        }

        if (! str_starts_with($platformKey, 'facebook_') && ! str_starts_with($platformKey, 'linkedin')) {
            throw new \InvalidArgumentException('Plataforma no soportada para generación con IA.');
        }

        $platform = Platform::from($platformKey);
        $model = AiSettings::resolveConfiguredModel(
            config("social.platforms.{$platformKey}.caption.model"),
        );

        $llm = $this->clientFactory->client($providerEnum);

        $raw = $llm->generate(
            SocialArticleCaptionPrompt::system($platform),
            SocialArticleCaptionPrompt::user(
                $post->title,
                $post->excerpt,
                $platform,
                $post->siteLabel(),
            ),
            $model,
        );

        return $this->parseCaption($raw);
    }

    private function parseCaption(string $raw): string
    {
        $json = json_decode(trim($raw), true);

        if (is_array($json) && isset($json['caption']) && is_string($json['caption'])) {
            return trim($json['caption']);
        }

        return trim($raw);
    }
}
