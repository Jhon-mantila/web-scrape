<?php

namespace App\ProcessScraping\Support;

class FranchiseKeyNormalizer
{
    public static function fromFranchiseName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($name));
        $ascii = is_string($ascii) ? $ascii : mb_strtolower($name);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? $ascii;
        $ascii = trim($ascii, '-');

        return $ascii !== '' ? $ascii : null;
    }
}
