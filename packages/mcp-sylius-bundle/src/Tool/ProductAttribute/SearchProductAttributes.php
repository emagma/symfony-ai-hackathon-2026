<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAttribute;

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
 * Lists the product attributes, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_product_attributes',
    description: 'Search product attributes (code + translated name). Filters: name (partial,'
        . ' translated), code (partial), type (exact, e.g. "text", "select", "integer", "float", "percent",'
        . ' "checkbox", "date", "datetime"), translatable (true/false). Detail: get_product_attributes.',
)]
#[Permission(resource: 'product_attribute', action: Action::Read)]
final readonly class SearchProductAttributes
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
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
        ?string $name = null,
        ?string $code = null,
        ?string $type = null,
        ?bool $translatable = null,
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        $query = array_filter([
            'translations.name' => $name,
            'code' => $code,
            'type' => $type,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        if (null !== $translatable) {
            $query['translatable'] = $translatable ? 'true' : 'false';
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::ProductAttribute), $query, self::leanLocaleHeaders($locale));
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::text($member['code']),
                'name' => AdminPayload::translation($member['translations'] ?? [], $locale, 'name'),
            ];
        }

        return $pager->envelope($items, $total);
    }
}
