<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Zone;

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
 * Lists the zones, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_zones',
    description: 'Search zones (code + name + type + scope). Filters (all optional, client-side): code'
        . ' (partial, case-insensitive), name (partial, case-insensitive), type (exact: "country" |'
        . ' "province" | "zone"), scope (exact: "tax" | "shipping" | "all").
 Detail: get_zones.',
)]
#[Permission(resource: 'zone', action: Action::Read)]
final readonly class SearchZones
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null, type: string|null, scope: string|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $code = null,
        ?string $name = null,
        ?string $type = null,
        ?string $scope = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $payload = $this->client->getDecoded(Route::collection(Resource::Zone), [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ], self::leanHeaders());
        $members = AdminPayload::members($payload);

        $matched = [];
        $codeNeedle = null !== $code && '' !== $code ? mb_strtolower($code) : null;
        $nameNeedle = null !== $name && '' !== $name ? mb_strtolower($name) : null;
        foreach ($members as $member) {
            $itemCode = \is_string($member['code'] ?? null) ? $member['code'] : '';
            $itemType = \is_string($member['type'] ?? null) ? $member['type'] : null;
            $itemScope = \is_string($member['scope'] ?? null) ? $member['scope'] : null;
            if (null !== $type && $itemType !== $type) {
                continue;
            }
            if (null !== $scope && $itemScope !== $scope) {
                continue;
            }
            if (null !== $codeNeedle && !str_contains(mb_strtolower($itemCode), $codeNeedle)) {
                continue;
            }
            $itemName = \is_string($member['name'] ?? null) ? $member['name'] : null;
            if (null !== $nameNeedle && (null === $itemName || !str_contains(mb_strtolower($itemName), $nameNeedle))) {
                continue;
            }
            $matched[] = ['code' => $itemCode, 'name' => $itemName, 'type' => $itemType, 'scope' => $itemScope];
        }

        $total = \count($matched);

        return $pager->envelope($pager->slice($matched), $total);
    }
}
