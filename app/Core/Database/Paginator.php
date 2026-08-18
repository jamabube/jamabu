<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Pagination result.
 *
 * Every listing endpoint and every data table in the interface is fed by one
 * of these, so paging behaviour is identical everywhere.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
final class Paginator
{
    /**
     * @param list<array<string,mixed>> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage
    ) {
    }

    /**
     * Run a paginated query, returning both the page of rows and the totals.
     *
     * The count runs before the page query so an out-of-range page can be
     * clamped rather than returning an empty table with no explanation.
     */
    public static function fromQuery(QueryBuilder $query, int $page, int $perPage): self
    {
        $perPage = self::clampPerPage($perPage);
        $total   = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $lastPage));

        $items = $query->forPage($page, $perPage)->get();

        return new self($items, $total, $perPage, $page);
    }

    /**
     * Clamp a requested page size to the configured bounds.
     */
    public static function clampPerPage(int $perPage): int
    {
        $default = (int) config('app.pagination.default_per_page', 25);
        $max     = (int) config('app.pagination.max_per_page', 200);

        if ($perPage <= 0) {
            return $default;
        }

        return min($perPage, $max);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * Index of the first row on this page, 1-based; 0 when the page is empty.
     */
    public function from(): int
    {
        return $this->items === [] ? 0 : (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return $this->items === [] ? 0 : $this->from() + count($this->items) - 1;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function previousPage(): ?int
    {
        return $this->hasPrevious() ? $this->currentPage - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->hasNext() ? $this->currentPage + 1 : null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Apply a transformation to every row, preserving the pagination metadata.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     */
    public function map(callable $callback): self
    {
        return new self(
            array_map($callback, $this->items),
            $this->total,
            $this->perPage,
            $this->currentPage
        );
    }

    /**
     * The page-number sequence to render, with null marking an elision.
     *
     * @return list<int|null>
     */
    public function pageWindow(int $onEachSide = 2): array
    {
        $last = $this->lastPage();

        if ($last <= 7) {
            return range(1, $last);
        }

        $window = [1];
        $start  = max(2, $this->currentPage - $onEachSide);
        $end    = min($last - 1, $this->currentPage + $onEachSide);

        if ($start > 2) {
            $window[] = null;
        }

        for ($page = $start; $page <= $end; $page++) {
            $window[] = $page;
        }

        if ($end < $last - 1) {
            $window[] = null;
        }

        $window[] = $last;

        return $window;
    }

    /**
     * Pagination metadata for the API envelope.
     *
     * @return array<string,int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'total'         => $this->total,
            'per_page'      => $this->perPage,
            'current_page'  => $this->currentPage,
            'last_page'     => $this->lastPage(),
            'from'          => $this->from(),
            'to'            => $this->to(),
            'has_previous'  => $this->hasPrevious(),
            'has_next'      => $this->hasNext(),
            'previous_page' => $this->previousPage(),
            'next_page'     => $this->nextPage(),
        ];
    }
}
