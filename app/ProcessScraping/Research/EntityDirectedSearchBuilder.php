<?php

namespace App\ProcessScraping\Research;

class EntityDirectedSearchBuilder
{
    /**
     * @param  array<string, mixed>  $entities
     * @return list<string>
     */
    public function build(array $entities): array
    {
        $franchise = trim((string) ($entities['franchise'] ?? ''));

        if ($franchise === '') {
            return [];
        }

        $quoted = '"'.str_replace('"', '', $franchise).'"';
        $queries = [
            $quoted.' -site:animenewsnetwork.com',
            $quoted.' anime doblaje latino',
            $quoted.' manga editorial español Panini Ivrea Norma',
            $quoted.' crunchyroll latinoamérica',
        ];

        $extra = $entities['search_queries_es'] ?? [];

        if (is_array($extra)) {
            foreach ($extra as $q) {
                if (is_string($q) && trim($q) !== '') {
                    $queries[] = trim($q);
                }
            }
        }

        $queries = array_values(array_unique(array_filter($queries)));

        return array_slice($queries, 0, (int) config('services.searxng.max_queries', 3));
    }
}
