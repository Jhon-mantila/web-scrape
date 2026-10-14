<?php

namespace App\ProcessScraping\Support;

use RuntimeException;

class ArticleQualitySkipException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $isTooShort = false,
    ) {
        parent::__construct($message);
    }

    public static function fromMessage(string $message): self
    {
        return new self($message);
    }

    public static function tooShort(string $message): self
    {
        return new self($message, true);
    }
}
