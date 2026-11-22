<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Taxon;

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
 * The vendor admin /taxons resource declares no filter: the criteria are the plugin's
 * lean-gated TaxonCriteriaFilter.
 *
 * @internal
 */
#[McpTool(
    name: 'search_taxons',
    description: 'Search taxons (code + translated name). Filters (all optional, server-side): name'
        . ' (partial, translated, case-insensitive), code (partial, case-insensitive), parentCode (exact'
        . ' code of the direct parent), rootOnly (true → only top-level taxons), enabled (true/false → only'
        . ' enabled or only disabled). Items come in tree order (parents first, siblings as the admin sorts'
        . ' them). Use list_taxon_tree to walk the hierarchy in a single call. Detail: get_taxons.',
)]
#[Permission(resource: 'taxon', action: Action::Read)]
final readonly class SearchTaxons
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
    ) {
    }

    /**
     * @return array{items: list<array{code: string, name: ?string}>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $name = null,
        ?string $code = null,
        ?string $parentCode = null,
        ?bool $rootOnly = null,
        ?bool $enabled = null,
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        // `name` is scoped to the locale carried by the Prefer header.
        $query = array_filter([
            'name' => $name,
            'code' => $code,
            'parentCode' => $parentCode,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        foreach (['rootOnly' => $rootOnly, 'enabled' => $enabled] as $key => $flag) {
            if (null !== $flag) {
                $query[$key] = $flag ? 'true' : 'false';
            }
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::Taxon), $query, self::leanLocaleHeaders($locale));
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::text($member['code'] ?? null),
                'name' => AdminPayload::translation($member['translations'] ?? [], $locale, 'name'),
            ];
        }

        return $pager->envelope($items, $total);
    }
}
