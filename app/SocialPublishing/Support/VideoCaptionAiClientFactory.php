<?php

namespace App\SocialPublishing\Support;

use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Ai\DeepSeekClient;
use App\ProcessScraping\Ai\OllamaClient;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use Illuminate\Contracts\Foundation\Application;

class VideoCaptionAiClientFactory
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function client(VideoCaptionAiProvider|string $provider): TextGenerationClient
    {
        $provider = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored(is_string($provider) ? $provider : null);

        return match ($provider) {
            VideoCaptionAiProvider::Deepseek => $this->app->make(DeepSeekClient::class),
            VideoCaptionAiProvider::OllamaLocal => $this->app->make(OllamaClient::class),
            VideoCaptionAiProvider::None => throw new \InvalidArgumentException('Este video no usa IA para captions.'),
        };
    }

    public function deepseekConfigured(): bool
    {
        return filled(config('services.deepseek.api_key'));
    }

    /**
     * @return list<array{value: string, label: string, hint: string, disabled: bool}>
     */
    public function optionsForUi(): array
    {
        $options = [
            [
                'value' => VideoCaptionAiProvider::OllamaLocal->value,
                'label' => VideoCaptionAiProvider::OllamaLocal->label(),
                'hint' => 'Modelos en OLLAMA_URL (sin coste por token).',
                'disabled' => false,
            ],
        ];

        $options[] = [
            'value' => VideoCaptionAiProvider::Deepseek->value,
            'label' => VideoCaptionAiProvider::Deepseek->label(),
            'hint' => $this->deepseekConfigured()
                ? 'Una petición para todas las plataformas (ahorro de tokens).'
                : 'Configura DEEPSEEK_API_KEY en .env para habilitar.',
            'disabled' => ! $this->deepseekConfigured(),
        ];

        $options[] = [
            'value' => VideoCaptionAiProvider::None->value,
            'label' => VideoCaptionAiProvider::None->label(),
            'hint' => 'Escribes los textos a mano; no se llama a la IA.',
            'disabled' => false,
        ];

        return $options;
    }
}
