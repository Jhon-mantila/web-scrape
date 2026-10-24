<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsDetail;
use App\Neuron\Agents\EditorialAgent;
use App\ProcessScraping\Ai\AiArticleResponseParser;
use App\ProcessScraping\Support\ArticleQualityReviewer;
use App\Services\EditorialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Tests\TestCase;

class EditorialAgentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editorial_cycle_updates_ai_status_and_parses_article(): void
    {
        config(['services.editorial_agent.observer' => 'log']);

        /** @var list<string> $logged */
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = (string) $event->message;
        });

        $outline = "## Intro\n- Punto\n\n## Desarrollo\n- Detalle";
        $draftJson = '{"title":"Titulo IA","excerpt":"Resumen del articulo de prueba con suficiente longitud.","html":"<p>Parrafo uno.</p><p>Parrafo dos.</p><p>Parrafo tres.</p>"}';
        $critiqueJson = '{"needs_refinement":false,"feedback":"Coherente con research","issues":[]}';

        $fakeProvider = new FakeAIProvider(
            AssistantMessage::make($outline),
            AssistantMessage::make($draftJson),
            AssistantMessage::make($critiqueJson),
        );

        $agent = new class($fakeProvider) extends EditorialAgent
        {
            public function __construct(private readonly AIProviderInterface $fake) {}

            protected function provider(): AIProviderInterface
            {
                return $this->fake;
            }
        };

        $news = News::query()->create([
            'title' => 'Anime de prueba',
            'url' => 'https://example.com/news/1',
            'source' => 'test',
            'status_ia' => null,
        ]);

        $detail = NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => str_repeat('Contenido scrapeado. ', 40),
            'research_context' => 'Contexto verificado de la franquicia.',
            'research_raw' => ['hits' => []],
            'raw_html' => '<p>html</p>',
        ]);

        $service = new EditorialService(new AiArticleResponseParser(), new ArticleQualityReviewer(), $agent);
        $result = $service->generateForNews($news->fresh(['detail']), 'noticia');

        $this->assertSame('Titulo IA', $result['parts']['generated_title']);
        $this->assertNotEmpty($result['parts']['body_html']);
        $this->assertStringContainsString('<p>', $result['parts']['body_html']);

        $detail->refresh();
        $this->assertSame('completed', $detail->ai_status);
        $this->assertNotNull($detail->ai_status_updated_at);

        $stepLogs = array_filter($logged, fn (string $m) => str_contains($m, '[EditorialAgent] Paso'));
        $this->assertGreaterThanOrEqual(3, count($stepLogs));
        $this->assertTrue(
            collect($logged)->contains(fn (string $m) => str_contains($m, 'Planificando outline'))
        );
    }
}
