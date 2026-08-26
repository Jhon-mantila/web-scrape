<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use App\ProcessScraping\Ai\OllamaClient;
use App\SocialPublishing\Prompts\SocialTitlePrompt;

class GenerateSocialTitleAction
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {}

    public function execute(SocialVideo $video): SocialVideo
    {
        $model = config('services.ollama.model');

        $raw = $this->ollama->generate(
            SocialTitlePrompt::system(),
            SocialTitlePrompt::user($video->title, $video->notes),
            $model,
        );

        $title = $this->parseTitle($raw) ?? trim($raw);

        if ($title !== '') {
            $video->update(['title' => mb_substr($title, 0, 255)]);
        }

        return $video->fresh();
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
