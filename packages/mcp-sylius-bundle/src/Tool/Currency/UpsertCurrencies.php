<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Currency;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates currencies in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_currencies',
    description: 'Create currencies in one call, at most 50 codes, matched on their code. A currency has no editable field,'
        . ' so an existing code is reported unchanged (or already_exists with mode create) and an unknown one is'
        . ' created (or not_found with mode update). One confirmation for the batch; one result per item.',
)]
#[Permission(resource: 'currency', action: Action::Write)]
final readonly class UpsertCurrencies
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: CurrencyItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_currencies', Route::collection(Resource::Currency), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'currency';
    }

    private function label(): string
    {
        return 'currencies';
    }

    private function validateItem(array $item): ?string
    {
        return [] === array_diff_key($item, ['code' => true]) ? null : 'only code is accepted';
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        return null === $current ? ['code' => ['before' => null, 'after' => $item['code']]] : [];
    }

    private function postBody(array $item, mixed $references): array
    {
        return ['code' => $item['code']];
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        return $current;
    }
}
