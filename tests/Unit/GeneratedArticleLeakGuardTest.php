<?php

namespace Tests\Unit;

use App\ProcessScraping\Support\GeneratedArticleLeakGuard;
use PHPUnit\Framework\TestCase;

class GeneratedArticleLeakGuardTest extends TestCase
{
    public function test_scrubs_internal_terms_from_html(): void
    {
        $html = '<p>Según research_context, el anime regresa.</p>';
        $clean = GeneratedArticleLeakGuard::scrub($html);

        $this->assertStringNotContainsString('research_context', mb_strtolower($clean));
        $this->assertStringContainsString('anime regresa', $clean);
    }

    public function test_detects_leak_after_failed_scrub_edge_case(): void
    {
        $this->assertSame(
            'research_raw',
            GeneratedArticleLeakGuard::findLeak('Ver research_raw en el panel'),
        );
    }

    public function test_validate_parts_returns_null_when_clean(): void
    {
        $this->assertNull(GeneratedArticleLeakGuard::validateParts([
            'generated_title' => 'Nuevo anime confirmado',
            'body_html' => '<p>La productora anunció la secuela.</p>',
        ]));
    }
}
