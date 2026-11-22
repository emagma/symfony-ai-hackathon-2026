<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductTaxon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\ProductTaxon\ProductTaxonOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the product taxons, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_product_taxons',
    description: 'Search Sylius admin product-to-taxon attachments (id, productCode, taxonCode, position). This is'
        . ' how you see the ORDER of products inside a taxon, and the attachment id that'
        . ' update_product_taxon_position takes. Filters (server-side): product (EXACT product code), taxon'
        . ' (EXACT taxon code) - pass taxon alone to see a category listing in order. Sorting: ascending'
        . ' by position by default; pass sortByPosition: false to keep the API order. Use'
        . ' update_product_taxons to attach or detach a product; this tool never writes. Detail: update_product_taxons to attach or move.',
)]
#[Permission(resource: 'product', action: Action::Read)]
#[Permission(resource: 'taxon', action: Action::Read)]
final readonly class SearchProductTaxons
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{items: list<ProductTaxonOutput>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $product = null,
        ?string $taxon = null,
        bool $sortByPosition = true,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $query = array_filter([
            'product.code' => $product,
            'taxon.code' => $taxon,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        if ($sortByPosition) {
            $query['order'] = ['position' => 'asc'];
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::ProductTaxon), $query, self::leanHeaders());
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            if (\is_array($member)) {
                $items[] = ProductTaxonOutput::fromPayload($member);
            }
        }

        return $pager->envelope($items, $total);
    }
}
