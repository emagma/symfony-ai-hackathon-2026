<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ExchangeRate;

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
 * Lists the exchange rates, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_exchange_rates',
    description: 'Search exchange rates (id + ratio + source/target currency codes). Filters (all'
        . ' optional, client-side): sourceCurrency (exact code), targetCurrency (exact code). Exchange rates'
        . ' are id-based, not code-based. Detail: get_exchange_rates (ids).',
)]
#[Permission(resource: 'exchange_rate', action: Action::Read)]
final readonly class SearchExchangeRates
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{id: int, ratio: float|null, sourceCurrencyCode: string|null, targetCurrencyCode: string|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $sourceCurrency = null,
        ?string $targetCurrency = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $payload = $this->client->getDecoded(Route::collection(Resource::ExchangeRate), [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ], self::leanHeaders());
        $members = AdminPayload::members($payload);

        $matched = [];
        foreach ($members as $member) {
            $source = AdminPayload::code($member['sourceCurrency'] ?? null);
            $target = AdminPayload::code($member['targetCurrency'] ?? null);
            if (null !== $sourceCurrency && $source !== $sourceCurrency) {
                continue;
            }
            if (null !== $targetCurrency && $target !== $targetCurrency) {
                continue;
            }
            $ratio = $member['ratio'] ?? null;
            $matched[] = [
                'id' => \is_int($member['id'] ?? null) ? $member['id'] : 0,
                'ratio' => \is_int($ratio) || \is_float($ratio) ? (float) $ratio : null,
                'sourceCurrencyCode' => $source,
                'targetCurrencyCode' => $target,
            ];
        }

        $total = \count($matched);

        return $pager->envelope($pager->slice($matched), $total);
    }
}
