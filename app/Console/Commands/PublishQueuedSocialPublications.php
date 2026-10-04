<?php

namespace App\Console\Commands;

use App\SocialPublishing\Actions\PublishDueQueuedSocialPublicationsAction;
use Illuminate\Console\Command;

class PublishQueuedSocialPublications extends Command
{
    protected $signature = 'social:publish-queued';

    protected $description = 'Envía publicaciones de video cuya fecha de cola (app) ya venció';

    public function handle(PublishDueQueuedSocialPublicationsAction $action): int
    {
        $summary = $action->execute();

        if ($summary['processed'] === 0) {
            $this->line('Nada en cola pendiente.');

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Cola procesada: %d · OK: %d · fallidas: %d · omitidas: %d',
            $summary['processed'],
            $summary['published'],
            $summary['failed'],
            $summary['skipped'],
        ));

        return $summary['failed'] > 0 && $summary['published'] === 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
