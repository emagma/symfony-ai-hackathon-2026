<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxRate;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates tax rates in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_tax_rates',
    description: 'Create or update tax rates in one call, at most 50 items, matched on their code. A creation'
        . ' needs name, amount (fraction: 0.2 = 20 %), zoneCode and categoryCode; an update changes only the'
        . ' fields given. mode: upsert (default) creates the missing and updates the rest, create refuses an'
        . ' existing code (already_exists), update refuses an unknown one (not_found). An item that changes'
        . ' nothing is unchanged and not written. One confirmation for the batch; one result per item.',
)]
#[Permission(resource: 'tax_rate', action: Action::Write)]
final readonly class UpsertTaxRates
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const array SCALARS = ['name', 'amount', 'includedInPrice', 'calculator', 'startDate', 'endDate'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: TaxRateItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_tax_rates', Route::collection(Resource::TaxRate), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'tax_rate';
    }

    private function label(): string
    {
        return 'tax rates';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'zoneCode', 'categoryCode', 'calculator'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        if (\array_key_exists('amount', $item) && !\is_int($item['amount']) && !\is_float($item['amount'])) {
            return 'amount is a number';
        }
        if (\array_key_exists('includedInPrice', $item) && !\is_bool($item['includedInPrice'])) {
            return 'includedInPrice is a boolean';
        }
        foreach (['startDate', 'endDate'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field] && !\is_string($item[$field])) {
                return $field . ' is a string or null';
            }
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            foreach (['name', 'amount', 'zoneCode', 'categoryCode'] as $field) {
                $diff[self::diffKey($field)] = ['before' => null, 'after' => $item[$field] ?? null];
            }
            $diff['includedInPrice'] = ['before' => null, 'after' => $item['includedInPrice'] ?? false];
            $diff['calculator'] = ['before' => null, 'after' => $item['calculator'] ?? 'default'];
            foreach (['startDate', 'endDate'] as $field) {
                if (\array_key_exists($field, $item) && null !== $item[$field]) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        $diff = [];
        foreach (self::SCALARS as $field) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $before = $current[$field] ?? null;
            if ('amount' === $field) {
                $before = (\is_int($before) || \is_float($before)) ? (float) $before : null;
            } elseif ('includedInPrice' === $field) {
                $before = (bool) $before;
            }
            $after = $item[$field];
            if ('amount' === $field) {
                $after = (\is_int($after) || \is_float($after)) ? (float) $after : null;
            }
            if ($before !== $after) {
                $diff[$field] = ['before' => $before, 'after' => $after];
            }
        }
        foreach (['zoneCode' => 'zone', 'categoryCode' => 'category'] as $field => $key) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $currentIri = \is_string($current[$key] ?? null) ? $current[$key] : null;
            if ($currentIri !== $this->iri($key, self::str($item[$field]))) {
                $diff[$key] = ['before' => null === $currentIri ? null : rawurldecode(basename($currentIri)), 'after' => $item[$field]];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = [
            'code' => $item['code'],
            'name' => $item['name'] ?? '',
            'amount' => $item['amount'] ?? 0,
            'includedInPrice' => $item['includedInPrice'] ?? false,
            'calculator' => $item['calculator'] ?? 'default',
            'zone' => $this->iri('zone', self::str($item['zoneCode'] ?? null)),
            'category' => $this->iri('category', self::str($item['categoryCode'] ?? null)),
        ];
        foreach (['startDate', 'endDate'] as $field) {
            if (\is_string($item[$field] ?? null)) {
                $body[$field] = $item[$field];
            }
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        foreach (self::SCALARS as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }
        foreach (['zoneCode' => 'zone', 'categoryCode' => 'category'] as $field => $key) {
            if (\array_key_exists($field, $item)) {
                $body[$key] = $this->iri($key, self::str($item[$field]));
            }
        }

        return $body;
    }

    private static function diffKey(string $field): string
    {
        return match ($field) {
            'zoneCode' => 'zone',
            'categoryCode' => 'category',
            default => $field,
        };
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function iri(string $key, string $code): string
    {
        return $this->adapter->iri('zone' === $key ? Resource::Zone : Resource::TaxCategory, $code);
    }
}
