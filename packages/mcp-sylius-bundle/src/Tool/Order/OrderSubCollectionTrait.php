<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Order;

use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Paginates client-side: an order's sub-collections are small by nature.
 * Carries `found`: a missing order must not read as an empty collection.
 *
 * @internal
 */
trait OrderSubCollectionTrait
{
    /**
     * @param list<object> $shaped
     *
     * @return array{found: true, items: list<object>, total: int, page: int, hasMore: bool}
     */
    private static function paginate(array $shaped, PageRequest $pager): array
    {
        return ['found' => true] + $pager->envelope($pager->slice($shaped), \count($shaped));
    }

    /**
     * @return array{found: false, tokenValue: string, items: list<object>, total: int, page: int, hasMore: bool}
     */
    private static function orderNotFound(string $tokenValue, PageRequest $pager): array
    {
        return ['found' => false, 'tokenValue' => $tokenValue] + $pager->envelope([], 0);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private static function members(array $payload): array
    {
        return AdminPayload::members($payload);
    }
}
