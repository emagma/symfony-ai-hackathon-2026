<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Country;

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
 * Creates or updates countries in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_countries',
    description: 'Create or enable/disable countries in one call, at most 50 items, matched on their ISO code. mode:'
        . ' upsert (default) creates the missing and updates the rest, create refuses an existing code'
        . ' (already_exists), update refuses an unknown one (not_found). Countries have no delete endpoint:'
        . ' disable instead. One confirmation for the batch; one result per item.',
)]
#[Permission(resource: 'country', action: Action::Write)]
final readonly class UpsertCountries
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
        #[Schema(type: 'array', items: CountryItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_countries', Route::collection(Resource::Country), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'country';
    }

    private function label(): string
    {
        return 'countries';
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            if (\array_key_exists('enabled', $item)) {
                $diff['enabled'] = ['before' => null, 'after' => $item['enabled']];
            }

            return $diff;
        }
        if (!\array_key_exists('enabled', $item)) {
            return [];
        }
        $before = (bool) ($current['enabled'] ?? false);

        return $before === $item['enabled'] ? [] : ['enabled' => ['before' => $before, 'after' => $item['enabled']]];
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = ['code' => $item['code']];
        if (\array_key_exists('enabled', $item)) {
            $body['enabled'] = $item['enabled'];
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        $body['enabled'] = $item['enabled'];

        return $body;
    }
}
