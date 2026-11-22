<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Payment;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Payment\PaymentDetailOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads payments one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_payments',
    description: 'Get Sylius admin payments by numeric id (search_payments and list_order_collection return it), at'
        . ' most 50 per call: state, amount, currencyCode, methodCode, timestamps, orderTokenValue. The'
        . ' gateway details blob is never returned. Returns {found: [...], missing: [ids]}.',
)]
#[Permission(resource: 'payment', action: Action::Read)]
final readonly class GetPayments
{
    private const int MAX_IDS = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array{found: list<PaymentDetailOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $ids,
    ): array {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== trim($id))));
        if (\count($ids) > self::MAX_IDS) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_ids'];
        }
        if ([] === $ids) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($ids as $id) {
            $paths[$id] = Route::item(Resource::Payment, $id);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $result = $results[$id] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = PaymentDetailOutput::fromPayload($result['body']);
            } else {
                $missing[] = $id;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
