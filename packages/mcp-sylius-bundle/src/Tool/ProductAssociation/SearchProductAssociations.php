<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\ProductAssociation\ProductAssociationSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the product associations, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_product_associations',
    description: 'Search Sylius admin product associations - the product-to-product links used for cross-selling'
        . ' ("Similar products", "Accessories"). Each row: id, ownerCode, typeCode, associatedProductCodes.'
        . ' Filters (server-side): owner (PARTIAL match on the owning product code), type (PARTIAL match on'
        . ' the association type code). The id is numeric and is what get/delete_product_associations'
        . ' take. Detail: get_product_associations (ids).',
)]
#[Permission(resource: 'product_association', action: Action::Read)]
final readonly class SearchProductAssociations
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{items: list<ProductAssociationSummaryOutput>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $owner = null,
        ?string $type = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'owner.code' => $owner,
            'type.code' => $type,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::ProductAssociation), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = ProductAssociationSummaryOutput::fromPayload($member);
        }

        return $pager->envelope($items, $total);
    }
}
