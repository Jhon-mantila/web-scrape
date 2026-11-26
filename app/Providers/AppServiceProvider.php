<?php

namespace App\Providers;

use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Ai\DeepSeekClient;
use App\ProcessScraping\Ai\OllamaApiClient;
use App\ProcessScraping\Ai\OllamaClient;
use App\ProcessScraping\Ai\Support\AiSettings;
use App\ProcessScraping\Images\Contracts\FeaturedImageUpscaleRunner;
use App\ProcessScraping\Images\OnnxRuntimeFeaturedImageUpscaleRunner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TextGenerationClient::class, function ($app) {
            return match (AiSettings::provider()) {
                'deepseek' => $app->make(DeepSeekClient::class),
                'ollama_api' => $app->make(OllamaApiClient::class),
                default => $app->make(OllamaClient::class),
            };
        });
        $this->app->bind(FeaturedImageUpscaleRunner::class, OnnxRuntimeFeaturedImageUpscaleRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
