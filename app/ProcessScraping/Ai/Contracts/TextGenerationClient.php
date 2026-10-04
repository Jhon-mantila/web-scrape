<?php

namespace App\ProcessScraping\Ai\Contracts;

interface TextGenerationClient
{
    public function generate(string $system, string $prompt, ?string $model = null): string;

    public function unloadModels(?string $model = null): void;

    public function supportsModelUnload(): bool;
}
