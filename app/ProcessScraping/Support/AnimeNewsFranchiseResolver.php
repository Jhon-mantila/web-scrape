<?php

namespace App\ProcessScraping\Support;

use App\Models\News;

class AnimeNewsFranchiseResolver
{
    public function franchiseName(News $news): ?string
    {
        $detail = $news->detail;

        if ($detail === null) {
            return null;
        }

        $sheet = $detail->fact_sheet;

        if (is_array($sheet) && ! empty($sheet['franchise'])) {
            return trim((string) $sheet['franchise']);
        }

        $raw = $detail->research_raw;

        if (is_array($raw)) {
            $entities = $raw['entities'] ?? null;

            if (is_array($entities) && ! empty($entities['franchise'])) {
                return trim((string) $entities['franchise']);
            }
        }

        return null;
    }

    public function franchiseKey(News $news): ?string
    {
        return FranchiseKeyNormalizer::fromFranchiseName($this->franchiseName($news));
    }

    public function newsType(News $news): ?string
    {
        $raw = $news->detail?->research_raw;

        if (! is_array($raw)) {
            return null;
        }

        $entities = $raw['entities'] ?? null;

        if (! is_array($entities)) {
            return null;
        }

        $type = trim((string) ($entities['news_type'] ?? ''));

        return $type !== '' ? mb_strtolower($type) : null;
    }

    public function anilistPopularity(News $news): ?int
    {
        $raw = $news->detail?->research_raw;

        if (! is_array($raw)) {
            return null;
        }

        $anilist = $raw['anilist'] ?? null;

        if (! is_array($anilist)) {
            return null;
        }

        $pop = $anilist['popularity'] ?? null;

        return is_numeric($pop) ? (int) $pop : null;
    }
}
