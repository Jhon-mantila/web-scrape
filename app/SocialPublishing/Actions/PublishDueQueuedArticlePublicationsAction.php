<?php

namespace App\SocialPublishing\Actions;

/**
 * Reservado para la cola «Enviar automáticamente (app)» en Artículos.
 * Cuando se añada queued_publish_at a social_article_publications, implementar aquí.
 */
class PublishDueQueuedArticlePublicationsAction
{
    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message: string}
     */
    public function execute(): array
    {
        return [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => 'Pendiente: enlazar cola de artículos con queued_publish_at en BD.',
        ];
    }
}
