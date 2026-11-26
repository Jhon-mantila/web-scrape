<?php

namespace App\ProcessScraping\Images\Contracts;

interface FeaturedImageUpscaleRunner
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public function run(string $inputPath, string $outputPath, string $modelPath, int $maxSide): array;
}
