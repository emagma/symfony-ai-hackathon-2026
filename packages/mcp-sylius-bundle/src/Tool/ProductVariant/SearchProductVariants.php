<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductVariant;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * The admin API has no nested `/products/{code}/variants`: narrowing by product is a filter.
 */
#[McpTool(
    name: 'search_product_variants',
    description: 'Search product variants (code, translated name, product code, enabled, onHand).'
        . ' Filters: product (exact product code - pass it whenever you want the variants of one'
        . ' product), name (partial, translated), code (partial). Ordered by position. Use'
        . ' get_product_variants for pricing, stock detail, dimensions and option values. Detail: get_product_variants.',
)]
#[Permission(resource: 'product_variant', action: Action::Read)]
final readonly class SearchProductVariants
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null, productCode: string|null, enabled: bool, onHand: int|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $product = null,
        ?string $name = null,
        ?string $code = null,
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        $query = array_filter([
            // The vendor SearchFilter matches the relation: the product code travels as an IRI.
            'product' => null !== $product && '' !== $product
                ? $this->adapter->iri(Resource::Product, $product)
                : null,
            'translations.name' => $name,
            'code' => $code,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::ProductVariant), $query, self::leanLocaleHeaders($locale));
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::text($member['code']),
                'name' => AdminPayload::translation($member['translations'] ?? [], $locale, 'name'),
                'productCode' => AdminPayload::code($member['product'] ?? null),
                'enabled' => (bool) ($member['enabled'] ?? false),
                'onHand' => AdminPayload::int($member['onHand'] ?? null),
            ];
        }

        return $pager->envelope($items, $total);
    }
}
