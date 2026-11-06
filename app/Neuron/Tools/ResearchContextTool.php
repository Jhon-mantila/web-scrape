<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\NewsDetail;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ResearchContextTool extends Tool
{
    protected string $name = 'consultar_investigacion';

    protected ?string $description = 'Devuelve la investigación verificada de la noticia (hechos y extractos de fuentes). Uso interno del editor; no citar nombres técnicos al lector.';

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'news_detail_id',
                type: PropertyType::INTEGER,
                description: 'Identificador interno del detalle de noticia (solo para invocar la herramienta)',
                required: true,
            ),
        ];
    }

    public function __invoke(int $news_detail_id): string
    {
        $detail = NewsDetail::query()->find($news_detail_id);

        if ($detail === null) {
            return json_encode([
                'error' => 'news_detail no encontrado',
                'news_detail_id' => $news_detail_id,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'investigacion_verificada' => $detail->research_context,
            'extractos_fuentes' => $detail->research_raw,
            'fecha_investigacion' => $detail->researched_at?->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
