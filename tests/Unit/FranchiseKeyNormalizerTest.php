<?php

namespace Tests\Unit;

use App\ProcessScraping\Support\FranchiseKeyNormalizer;
use PHPUnit\Framework\TestCase;

class FranchiseKeyNormalizerTest extends TestCase
{
    public function test_normalizes_franchise_name(): void
    {
        $this->assertSame(
            'the-apothecary-diaries',
            FranchiseKeyNormalizer::fromFranchiseName('The Apothecary Diaries'),
        );
    }
}
