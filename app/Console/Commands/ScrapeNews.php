<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Scraper\Actions\ScrapeNewsAction;

class ScrapeNews extends Command
{
    // Nombre del comando
    protected $signature = 'scrape:news';

    // Descripción
    protected $description = 'Scrapea noticias desde diferentes fuentes';

    public function handle()
    {
        $news = (new ScrapeNewsAction())->execute();

        foreach ($news as $item) {
            $this->info($item->title);
        }

        return Command::SUCCESS;
    }
}