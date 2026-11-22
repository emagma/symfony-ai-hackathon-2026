<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Taxon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * The plugin's lean collection comes in nested-set order (`subtreeOf` and `depth` filter it in SQL),
 * so the walk only re-threads levels. Paged through to the end, never capped.
 *
 * @internal
 */
#[McpTool(
    name: 'list_taxon_tree',
    description: 'Walk the taxonomy hierarchy and return a flat DFS pre-order list: [{code, name, parentCode,'
        . ' level}, ...], siblings in the order the admin sorts them. rootCode - start from this taxon'
        . ' (default: walk every root; an unknown code yields an empty list, not an error). depth - levels'
        . ' below the start to include (default 10, capped at 10). Spares the LLM the round-trips needed'
        . ' to explore the tree via search_taxons.',
)]
#[Permission(resource: 'taxon', action: Action::Read)]
final readonly class ListTaxonTree
{
    use LeanLocaleHeaderTrait;

    private const int DEPTH_HARD_CAP = 10;

    /**
     * Pages are stable: the lean collection is ordered by the nested-set `left` column (plugin).     */
    private const int FETCH_PAGE_SIZE = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
    ) {
    }

    /**
     * @return array{items: list<array{code: string, name: ?string, parentCode: ?string, level: int}>, total: int, depthCap: int}
     */
    public function __invoke(
        ?string $rootCode = null,
        int $depth = self::DEPTH_HARD_CAP,
        ?string $locale = null,
    ): array {
        $depth = max(0, min($depth, self::DEPTH_HARD_CAP));
        $locale ??= $this->defaultLocale;
        $rootCode = null !== $rootCode && '' !== $rootCode ? $rootCode : null;

        $members = $this->fetchAll($rootCode, $depth, $locale);

        $byCode = [];
        $childrenByParent = [];
        foreach ($members as $member) {
            $code = AdminPayload::text($member['code'] ?? null);
            if ('' === $code) {
                continue;
            }
            $byCode[$code] = $member;
            $parentCode = AdminPayload::code($member['parent'] ?? null) ?? '';
            $childrenByParent[$parentCode][] = $code;
        }

        $roots = null !== $rootCode
            ? (isset($byCode[$rootCode]) ? [$rootCode] : [])
            : ($childrenByParent[''] ?? []);

        $items = [];
        foreach ($roots as $rootCodeIter) {
            $this->walk($rootCodeIter, 0, $depth, $byCode, $childrenByParent, $locale, $items);
        }

        return [
            'items' => $items,
            'total' => \count($items),
            'depthCap' => $depth,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(?string $rootCode, int $depth, string $locale): array
    {
        $members = [];
        $page = 1;
        do {
            // `depth` is relative to `subtreeOf` when given, an absolute level otherwise.
            $query = ['depth' => $depth, 'page' => $page, 'itemsPerPage' => self::FETCH_PAGE_SIZE];
            if (null !== $rootCode) {
                $query = ['subtreeOf' => $rootCode] + $query;
            }

            $payload = $this->client->getDecoded(Route::collection(Resource::Taxon), $query, self::leanLocaleHeaders($locale));
            $pageMembers = AdminPayload::members($payload);
            foreach ($pageMembers as $member) {
                $members[] = $member;
            }

            $total = AdminPayload::totalItems($payload, \count($members));
            ++$page;
        } while ([] !== $pageMembers && \count($members) < $total);

        return $members;
    }

    /**
     * @param array<string, array<string, mixed>>                                      $byCode
     * @param array<string, list<string>>                                              $childrenByParent
     * @param list<array{code: string, name: ?string, parentCode: ?string, level: int}> $items
     */
    private function walk(
        string $code,
        int $level,
        int $depthCap,
        array $byCode,
        array $childrenByParent,
        string $locale,
        array &$items,
    ): void {
        if ($level > $depthCap || !isset($byCode[$code])) {
            return;
        }
        $node = $byCode[$code];
        $items[] = [
            'code' => $code,
            'name' => AdminPayload::translation($node['translations'] ?? [], $locale, 'name'),
            'parentCode' => AdminPayload::code($node['parent'] ?? null),
            'level' => $level,
        ];

        foreach ($childrenByParent[$code] ?? [] as $childCode) {
            $this->walk($childCode, $level + 1, $depthCap, $byCode, $childrenByParent, $locale, $items);
        }
    }
}
