<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\Scraper\Support\NewsScraperBulkDelete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DeleteNonProcessedScraperNewsAction
{
    public function __construct(
        private readonly NewsScraperBulkDelete $bulkDelete,
    ) {}

    /**
     * @return Builder<News>
     */
    public function keepQuery(): Builder
    {
        return News::query()
            ->where('status_ia', 'processed')
            ->whereHas('detail', function (Builder $query): void {
                $query->where('status', 'processed');
            });
    }

    /**
     * @return Builder<News>
     */
    public function deleteQuery(): Builder
    {
        return News::query()->where(function (Builder $query): void {
            $query
                ->where('status_ia', '!=', 'processed')
                ->orWhereDoesntHave('detail')
                ->orWhereHas('detail', function (Builder $detail): void {
                    $detail->where('status', '!=', 'processed');
                });
        });
    }

    public function countEligible(): int
    {
        return $this->deleteQuery()->count();
    }

    public function countKept(): int
    {
        return $this->keepQuery()->count();
    }

    /**
     * @return Collection<int, News>
     */
    public function eligibleNews(): Collection
    {
        return $this->deleteQuery()
            ->with(['detail', 'aiArticle'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{news_deleted: int, images_deleted: int, kept: int}
     */
    public function execute(): array
    {
        $summary = $this->bulkDelete->deleteMany($this->eligibleNews());

        return [
            ...$summary,
            'kept' => $this->countKept(),
        ];
    }
}
