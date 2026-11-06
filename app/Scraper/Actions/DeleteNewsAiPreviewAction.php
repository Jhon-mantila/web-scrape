<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\ProcessScraping\Support\AiGenerationProgress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteNewsAiPreviewAction
{
    public function execute(News $news): void
    {
        $news->loadMissing(['detail', 'aiArticle']);

        $ai = $news->aiArticle;

        if ($ai === null || blank($ai->body_html)) {
            throw new RuntimeException('Esta noticia no tiene un artículo IA con vista previa.');
        }

        if ($ai->sent_wordpress) {
            throw new RuntimeException('Este artículo ya fue enviado a WordPress y no se puede eliminar desde aquí.');
        }

        DB::transaction(function () use ($news, $ai): void {
            $ai->delete();

            $news->update(['status_ia' => null]);

            if ($news->detail !== null) {
                $news->detail->update([
                    'ai_status' => null,
                    'ai_status_updated_at' => null,
                ]);
            }
        });

        AiGenerationProgress::forget($news->id);
    }
}
