<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CustomerGroup;

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
 * Creates or updates customer groups in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_customer_groups',
    description: 'Create or update customer groups in one call, at most 50 items. Each item is matched on'
        . ' its code; mode decides what happens to a code that exists or not: upsert (default) creates'
        . ' the missing ones and updates the rest, create refuses an existing code (already_exists),'
        . ' update refuses an unknown one (not_found). An item that changes nothing is reported'
        . ' unchanged and not written. One confirmation for the whole batch. Returns {ok, batchId,'
        . ' total, created, updated, unchanged, failed, results: {code: {ok, action, diff|reason}}}.',
)]
#[Permission(resource: 'customer_group', action: Action::Write)]
final readonly class UpsertCustomerGroups
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
        #[Schema(type: 'array', items: CustomerGroupItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_customer_groups', Route::collection(Resource::CustomerGroup), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'customer_group';
    }

    private function label(): string
    {
        return 'customer groups';
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('name', $item) && (!\is_string($item['name']) || '' === trim($item['name']))) {
            return 'name is a non-empty string';
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            if (\array_key_exists('name', $item)) {
                $diff['name'] = ['before' => null, 'after' => $item['name']];
            }

            return $diff;
        }
        if (!\array_key_exists('name', $item)) {
            return [];
        }
        $before = \is_string($current['name'] ?? null) ? $current['name'] : null;

        return $before === $item['name'] ? [] : ['name' => ['before' => $before, 'after' => $item['name']]];
    }

    private function postBody(array $item, mixed $references): array
    {
        return ['code' => $item['code'], 'name' => $item['name'] ?? ''];
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        return ['code' => $current['code'] ?? $item['code'], 'name' => $item['name'] ?? ($current['name'] ?? '')];
    }
}
