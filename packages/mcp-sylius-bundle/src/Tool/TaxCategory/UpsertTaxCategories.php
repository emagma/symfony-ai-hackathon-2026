<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxCategory;

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
 * Creates or updates tax categories in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_tax_categories',
    description: 'Create or update tax categories in one call, at most 50 items. Each item is matched on'
        . ' its code; mode decides what happens to a code that exists or not: upsert (default) creates'
        . ' the missing ones and updates the rest, create refuses an existing code (already_exists),'
        . ' update refuses an unknown one (not_found). An item that changes nothing is reported'
        . ' unchanged and not written. One confirmation for the whole batch. Returns {ok, batchId,'
        . ' total, created, updated, unchanged, failed, results: {code: {ok, action, diff|reason}}}.',
)]
#[Permission(resource: 'tax_category', action: Action::Write)]
final readonly class UpsertTaxCategories
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
        #[Schema(type: 'array', items: TaxCategoryItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_tax_categories', Route::collection(Resource::TaxCategory), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'tax_category';
    }

    private function label(): string
    {
        return 'tax categories';
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('name', $item) && (!\is_string($item['name']) || '' === trim($item['name']))) {
            return 'name is a non-empty string';
        }
        if (\array_key_exists('description', $item) && null !== $item['description'] && !\is_string($item['description'])) {
            return 'description is a string or null';
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            foreach (['name', 'description'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        $diff = [];
        foreach (['name', 'description'] as $field) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $before = \is_string($current[$field] ?? null) ? $current[$field] : null;
            if ($before !== $item[$field]) {
                $diff[$field] = ['before' => $before, 'after' => $item[$field]];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = ['code' => $item['code'], 'name' => $item['name'] ?? ''];
        if (\is_string($item['description'] ?? null)) {
            $body['description'] = $item['description'];
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = [
            'code' => $current['code'] ?? $item['code'],
            'name' => $item['name'] ?? ($current['name'] ?? ''),
        ];
        $description = \array_key_exists('description', $item) ? $item['description'] : ($current['description'] ?? null);
        if (null !== $description) {
            $body['description'] = $description;
        }

        return $body;
    }
}
