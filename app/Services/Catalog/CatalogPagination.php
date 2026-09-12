<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

final readonly class CatalogPagination
{
    public int $page;

    public int $limit;

    public int $total;

    public int $totalPages;

    public bool $hasNext;

    public bool $hasPrevious;

    public function __construct(
        int $page,
        int $limit,
        int $total,
    ) {
        $page = max(CatalogSearchQuery::DEFAULT_PAGE, $page);
        $limit = max(1, $limit);
        $total = max(0, $total);

        $totalPages = 0;

        if ($total > 0) {
            $totalPages = (int) ceil($total / $limit);
        }

        $this->page = $page;
        $this->limit = $limit;
        $this->total = $total;
        $this->totalPages = $totalPages;
        $this->hasNext = $page < $totalPages;
        $this->hasPrevious = $page > CatalogSearchQuery::DEFAULT_PAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'has_next' => $this->hasNext,
            'has_previous' => $this->hasPrevious,
        ];
    }
}