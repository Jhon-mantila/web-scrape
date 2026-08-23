<?php

namespace App\SendWordpress;

readonly class WordPressAccount
{
    public function __construct(
        public string $user,
        public string $password,
        public string $label = 'primary',
    ) {}
}
