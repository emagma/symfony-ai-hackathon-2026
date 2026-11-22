<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ExchangeRate;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ExchangeRate\ExchangeRateOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads exchange rates one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_exchange_rates',
    description: 'Get exchange rates by numeric id, at most 50 per call: id, ratio, source and target currency'
        . ' codes. Returns {found: [...], missing: [ids]}. Discover ids with search_exchange_rates.',
)]
#[Permission(resource: 'exchange_rate', action: Action::Read)]
final readonly class GetExchangeRates
{
    private const int MAX_IDS = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array{found: list<ExchangeRateOutput>, missing: list<int>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'integer'], minItems: 1)]
        array $ids,
    ): array {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if (\count($ids) > self::MAX_IDS) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_ids'];
        }
        if ([] === $ids) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($ids as $id) {
            $paths[$id] = 'exchange-rates/' . $id;
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $result = $results[$id] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = ExchangeRateOutput::fromPayload($result['body'], $id);
            } else {
                $missing[] = $id;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
