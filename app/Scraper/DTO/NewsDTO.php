<?php

namespace App\Scraper\DTO;

class NewsDTO
{
    public function __construct(
        public string $title,
        public string $url,
        public string $source,
        public ?string $image = null,
        public ?string $description = null,
    ) {}
}
