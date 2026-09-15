<?php

namespace App\SocialPublishing\Actions;

use App\Models\WordpressPost;
use App\ProcessScraping\Ai\OllamaClient;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Prompts\SocialArticleCaptionPrompt;

class GenerateSocialArticleCaptionAction
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    /**
     * @param  list<string>  $platformKeys
     * @return array<string, string>
     */
    public function executeMany(WordpressPost $post, array $platformKeys): array
    {
        $captions = [];

        foreach ($platformKeys as $platformKey) {
            $captions[$platformKey] = $this->execute($post, $platformKey);
        }

        return $captions;
    }

    public function execute(WordpressPost $post, string $platformKey): string
    {
        if (! str_starts_with($platformKey, 'facebook_') && ! str_starts_with($platformKey, 'linkedin')) {
            throw new \InvalidArgumentException('Plataforma no soportada para generación con IA.');
        }

        $platform = Platform::from($platformKey);
        $model = config("social.platforms.{$platformKey}.caption.model");

        $raw = $this->ollama->generate(
            SocialArticleCaptionPrompt::system($platform),
            SocialArticleCaptionPrompt::user(
                $post->title,
                $post->excerpt,
                $platform,
                $post->siteLabel(),
            ),
            is_string($model) ? $model : null,
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
