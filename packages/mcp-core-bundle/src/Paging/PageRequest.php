<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Paging;

/**
 * The page of a search tool: `page` and `itemsPerPage` clamped to 1..50 (30 by default), and the envelope of its answer.
 */
final readonly class PageRequest
{
    public const int MAX_ITEMS_PER_PAGE = 50;

    public const int DEFAULT_ITEMS_PER_PAGE = 30;

    private function __construct(
        public int $page,
        public int $itemsPerPage,
    ) {
    }

    public static function of(int $page = 1, int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE): self
    {
        return new self(max(1, $page), max(1, min($itemsPerPage, self::MAX_ITEMS_PER_PAGE)));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->itemsPerPage;
    }

    /**
     * @template T
     *
     * @param list<T> $all
     *
     * @return list<T>
     */
    public function slice(array $all): array
    {
        return \array_slice($all, $this->offset(), $this->itemsPerPage);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return array{items: list<T>, total: int, page: int, hasMore: bool}
     */
    public function envelope(array $items, int $total): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $this->page,
            'hasMore' => ($this->page * $this->itemsPerPage) < $total,
        ];
    }
}
