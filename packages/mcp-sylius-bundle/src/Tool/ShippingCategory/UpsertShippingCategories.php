<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingCategory;

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
 * Creates or updates shipping categories in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_shipping_categories',
    description: 'Create or update shipping categories in one call, at most 50 items, matched on their code. mode:'
        . ' upsert (default) creates the missing and updates the rest, create refuses an existing code'
        . ' (already_exists), update refuses an unknown one (not_found). An item that changes nothing is'
        . ' unchanged and not written. One confirmation for the batch; one result per item.',
)]
#[Permission(resource: 'shipping_category', action: Action::Write)]
final readonly class UpsertShippingCategories
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
        #[Schema(type: 'array', items: ShippingCategoryItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_shipping_categories', Route::collection(Resource::ShippingCategory), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'shipping_category';
    }

    private function label(): string
    {
        return 'shipping categories';
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
        $body = $current;
        foreach (['name', 'description'] as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }

        return $body;
    }
}
