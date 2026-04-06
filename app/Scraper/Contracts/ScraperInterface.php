<?php

namespace App\Scraper\Contracts;

interface ScraperInterface
{
    public function scrape(): array;
}
