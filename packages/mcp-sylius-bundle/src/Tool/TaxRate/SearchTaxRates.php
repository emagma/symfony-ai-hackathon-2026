<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxRate;

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
 * Lists the tax rates, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_tax_rates',
    description: 'Search tax rates (code + name + amount ratio). Filters (all optional, client-side):'
        . ' code (partial, case-insensitive), name (partial, case-insensitive), zone (exact zone code),'
        . ' category (exact tax category code).
 Detail: get_tax_rates.',
)]
#[Permission(resource: 'tax_rate', action: Action::Read)]
final readonly class SearchTaxRates
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null, amount: float|null, zoneCode: string|null, categoryCode: string|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $code = null,
        ?string $name = null,
        ?string $zone = null,
        ?string $category = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $payload = $this->client->getDecoded(Route::collection(Resource::TaxRate), [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ], self::leanHeaders());
        $members = AdminPayload::members($payload);

        $matched = [];
        $codeNeedle = null !== $code && '' !== $code ? mb_strtolower($code) : null;
        $nameNeedle = null !== $name && '' !== $name ? mb_strtolower($name) : null;
        foreach ($members as $member) {
            $itemCode = \is_string($member['code'] ?? null) ? $member['code'] : '';
            $zoneCode = AdminPayload::code($member['zone'] ?? null);
            $categoryCode = AdminPayload::code($member['category'] ?? null);
            if (null !== $zone && $zoneCode !== $zone) {
                continue;
            }
            if (null !== $category && $categoryCode !== $category) {
                continue;
            }
            if (null !== $codeNeedle && !str_contains(mb_strtolower($itemCode), $codeNeedle)) {
                continue;
            }
            $itemName = \is_string($member['name'] ?? null) ? $member['name'] : null;
            if (null !== $nameNeedle && (null === $itemName || !str_contains(mb_strtolower($itemName), $nameNeedle))) {
                continue;
            }
            $amount = $member['amount'] ?? null;
            $matched[] = [
                'code' => $itemCode,
                'name' => $itemName,
                'amount' => \is_int($amount) || \is_float($amount) ? (float) $amount : null,
                'zoneCode' => $zoneCode,
                'categoryCode' => $categoryCode,
            ];
        }

        $total = \count($matched);

        return $pager->envelope($pager->slice($matched), $total);
    }
}
