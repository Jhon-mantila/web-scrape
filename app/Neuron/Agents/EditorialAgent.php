<?php

declare(strict_types=1);

namespace App\Neuron\Agents;

use App\Neuron\Support\EditorialAgentObservability;
use App\Neuron\Tools\ResearchContextTool;
use App\ProcessScraping\Prompts\AnimeNewsWritingGuidelines;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Laravel\Facades\AIProvider;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Agente editorial Neuron: planifica, redacta, autocritica y refina artículos de anime.
 *
 * El ciclo de cuatro pasos se ejecuta en {@see \App\Services\EditorialService} (Opción A:
 * varias llamadas chat() por simplicidad y trazabilidad por paso).
 */
class EditorialAgent extends Agent
{
    public function __construct()
    {
        parent::__construct();
        EditorialAgentObservability::attach($this);
    }

    protected function provider(): AIProviderInterface
    {
        return AIProvider::driver(config('neuron.provider.default', 'ollama'));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'Eres un editor senior especializado en noticias de anime y manga para EsquinaAnime.',
                'Trabajas con material scrapeado (a menudo en inglés) e investigación verificada.',
                'Redactas SIEMPRE en español neutro (Latinoamérica y España). Nunca publiques título ni cuerpo en inglés.',
                'No inventes hechos: si falta información, dilo explícitamente.',
                'Nunca menciones al lector nombres de base de datos, herramientas internas ni IDs técnicos. Integra la investigación como periodismo natural.',
                AnimeNewsWritingGuidelines::sourceContext(),
                AnimeNewsWritingGuidelines::structureAndStyle(),
            ],
            steps: [
                '1. Planificación: analiza título, texto scrapeado e investigación verificada (herramienta consultar_investigacion cuando se indique) y produce un outline estructurado.',
                '2. Redacción: escribe el artículo siguiendo el outline, tono informativo y cercano, en HTML semántico dentro del JSON final cuando se pida.',
                '3. Autocrítica: compara el borrador con la investigación verificada; señala omisiones, invenciones o tono inadecuado.',
                '4. Refinamiento: si hace falta, corrige el borrador según el feedback manteniendo formato solicitado.',
            ],
            output: [
                'Sigue las instrucciones de cada mensaje de usuario sobre formato (outline Markdown, JSON de borrador, JSON de crítica, JSON final).',
                'Para artículos finales usa JSON con claves: title, excerpt, html (fragmento HTML del cuerpo, sin documento completo).',
                'Prohibido que title, excerpt o html contengan jerga técnica del sistema.',
            ],
        );
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        return [
            new ResearchContextTool(),
        ];
    }
}
