<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductOption;

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
 * Lists the product options, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_product_options',
    description: 'Search product options (code + translated name). Filters: name (partial,'
        . ' translated), code (partial). Detail: get_product_options.',
)]
#[Permission(resource: 'product_option', action: Action::Read)]
final readonly class SearchProductOptions
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
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        $query = array_filter([
            'translations.name' => $name,
            'code' => $code,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        $payload = $this->client->getDecoded(Route::collection(Resource::ProductOption), $query, self::leanLocaleHeaders($locale));
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
