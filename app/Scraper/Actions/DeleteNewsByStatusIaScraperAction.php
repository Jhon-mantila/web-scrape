<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\Scraper\Support\NewsScraperBulkDelete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeleteNewsByStatusIaScraperAction
{
    /** Clave API/UI para status_ia NULL o cadena vacía. */
    public const NULL_KEY = '__null__';

    public function __construct(
        private readonly NewsScraperBulkDelete $bulkDelete,
    ) {}

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public function breakdown(): array
    {
        /** @var list<object{status_ia: ?string, aggregate: int|string}> $rows */
        $rows = News::query()
            ->select('status_ia', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status_ia')
            ->orderByDesc('aggregate')
            ->get()
            ->all();

        $merged = [];

        foreach ($rows as $row) {
            $key = $this->normalizeKey($row->status_ia);
            $merged[$key] = ($merged[$key] ?? 0) + (int) $row->aggregate;
        }

        $items = [];

        foreach ($merged as $key => $count) {
            $items[] = [
                'key' => $key,
                'label' => $this->labelForKey($key),
                'count' => $count,
            ];
        }

        usort($items, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $items;
    }

    /**
     * @param  list<string>  $statusKeys
     */
    public function countForKeys(array $statusKeys): int
    {
        $statusKeys = $this->normalizeSelectedKeys($statusKeys);

        if ($statusKeys === []) {
            return 0;
        }

        return $this->queryForKeys($statusKeys)->count();
    }

    /**
     * @param  list<string>  $statusKeys
     * @return array{news_deleted: int, images_deleted: int, keys: list<string>}
     */
    public function execute(array $statusKeys): array
    {
        $statusKeys = $this->normalizeSelectedKeys($statusKeys);

        if ($statusKeys === []) {
            return [
                'news_deleted' => 0,
                'images_deleted' => 0,
                'keys' => [],
            ];
        }

        /** @var Collection<int, News> $items */
        $items = $this->queryForKeys($statusKeys)
            ->with(['detail', 'aiArticle'])
            ->orderBy('id')
            ->get();

        $summary = $this->bulkDelete->deleteMany($items);

        return [
            ...$summary,
            'keys' => $statusKeys,
        ];
    }

    /**
     * @param  list<string>  $statusKeys
     * @return Builder<News>
     */
    public function queryForKeys(array $statusKeys): Builder
    {
        return News::query()->where(function (Builder $query) use ($statusKeys): void {
            foreach ($statusKeys as $key) {
                if ($key === self::NULL_KEY) {
                    $query->orWhere(function (Builder $inner): void {
                        $inner->whereNull('status_ia')->orWhere('status_ia', '');
                    });

                    continue;
                }

                $query->orWhere('status_ia', $key);
            }
        });
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function normalizeSelectedKeys(array $keys): array
    {
        $allowed = array_column($this->breakdown(), 'key');
        $allowedMap = array_fill_keys($allowed, true);

        $normalized = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $key = trim($key);

            if ($key === '' || $key === 'null') {
                $key = self::NULL_KEY;
            }

            if (! isset($allowedMap[$key])) {
                continue;
            }

            $normalized[$key] = $key;
        }

        return array_values($normalized);
    }

    private function normalizeKey(?string $statusIa): string
    {
        if ($statusIa === null || trim($statusIa) === '') {
            return self::NULL_KEY;
        }

        return $statusIa;
    }

    private function labelForKey(string $key): string
    {
        if ($key === self::NULL_KEY) {
            return '(vacío / null)';
        }

        return $key;
    }
}
