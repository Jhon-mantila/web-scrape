<?php

namespace App\ProcessScraping\Images\Generators;

use App\Models\News;

class FeaturedImagePromptBuilder
{
    public function build(News $news): string
    {
        $title = $this->clean((string) $news->title);
        $category = $this->clean((string) ($news->category ?? 'anime'));

        $template = (string) config(
            'services.comfyui.prompt_template',
            'anime illustration, {title}, {category} theme, vibrant colors, cinematic lighting, detailed background, no text, no watermark, no logos'
        );

        $prompt = str_replace(
            ['{title}', '{category}'],
            [$title, $category],
            $template,
        );

        return mb_substr(trim($prompt), 0, (int) config('services.comfyui.prompt_max_chars', 320));
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
