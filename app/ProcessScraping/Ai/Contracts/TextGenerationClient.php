<?php

namespace App\ProcessScraping\Ai\Contracts;

use App\ProcessScraping\Ai\Support\AiGenerationOptions;

interface TextGenerationClient
{
    public function generate(
        string $system,
        string $prompt,
        ?string $model = null,
        ?AiGenerationOptions $options = null,
    ): string;

    public function unloadModels(?string $model = null): void;

    public function supportsModelUnload(): bool;
}
