<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ExchangeRate;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchDeleteTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Deletes exchange rates in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_exchange_rates',
    description: 'Delete exchange rates by numeric id, at most 50 per call, after one confirmation for the'
        . ' batch. Returns {ok, batchId, total, deleted, failed, results: {id: {ok, action, reason?}}}.'
        . ' Not revertable.',
)]
#[Permission(resource: 'exchange_rate', action: Action::Delete)]
final readonly class DeleteExchangeRates
{
    use BatchDeleteTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'integer'], minItems: 1)]
        array $ids,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runDelete('delete_exchange_rates', Route::collection(Resource::ExchangeRate), array_map(static fn (int $id): string => (string) $id, $ids), $confirm, $context);
    }

    private function targetType(): string
    {
        return 'exchange_rate';
    }

    private function label(): string
    {
        return 'exchange rates';
    }

    private function nameOf(array $current): ?string
    {
        $source = AdminPayload::code($current['sourceCurrency'] ?? null);
        $target = AdminPayload::code($current['targetCurrency'] ?? null);

        return null === $source || null === $target ? null : $source . ' -> ' . $target;
    }
}
