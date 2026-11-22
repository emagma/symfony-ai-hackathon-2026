<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CustomerGroup;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the customer groups, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_customer_groups',
    description: 'Search customer groups (code + name). Filters (all optional, client-side): code (partial,'
        . ' case-insensitive), name (partial, case-insensitive). Detail: get_customer_groups.',
)]
#[Permission(resource: 'customer_group', action: Action::Read)]
final readonly class SearchCustomerGroups
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $code = null,
        ?string $name = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'code' => $code,
            'name' => $name,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::CustomerGroup), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::text($member['code']),
                // CustomerGroup is not translatable - `name` is a plain column.
                'name' => \is_string($member['name'] ?? null) ? $member['name'] : null,
            ];
        }

        return $pager->envelope($items, $total);
    }
}
