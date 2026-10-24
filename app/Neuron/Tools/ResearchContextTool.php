<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\NewsDetail;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ResearchContextTool extends Tool
{
    protected string $name = 'research_context';

    protected ?string $description = 'Obtiene research_context y research_raw de una fila news_details por su id.';

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'news_detail_id',
                type: PropertyType::INTEGER,
                description: 'ID primario de la tabla news_details',
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
            'news_detail_id' => $detail->id,
            'news_id' => $detail->news_id,
            'research_context' => $detail->research_context,
            'research_raw' => $detail->research_raw,
            'researched_at' => $detail->researched_at?->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
