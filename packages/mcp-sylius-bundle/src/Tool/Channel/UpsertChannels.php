<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Channel;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * References are IRIs built from the codes, unchecked: the shop answers 422 for an unknown one.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_channels',
    description: 'Create or update channels in one call, at most 50 items, matched on their code. A creation'
        . ' needs name, hostname, baseCurrencyCode (create-only), defaultLocaleCode, currencyCodes and'
        . ' localeCodes; the base currency and default locale are added to the lists when omitted. On an'
        . ' update the three code lists replace the current ones; baseCurrencyCode is ignored. mode:'
        . ' upsert (default), create (already_exists on an existing code) or update (not_found on an'
        . ' unknown one). An item that changes nothing is unchanged and not written. One confirmation for'
        . ' the batch; one result per item. Tool-specific reasons: fields_required.',
)]
#[Permission(resource: 'channel', action: Action::Write)]
final readonly class UpsertChannels
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const array CREATE_FIELDS = ['name', 'hostname', 'baseCurrencyCode', 'defaultLocaleCode', 'currencyCodes', 'localeCodes'];

    private const array SCALARS = ['name', 'hostname', 'description', 'color', 'themeName', 'contactEmail', 'taxCalculationStrategy', 'enabled'];

    /** item field => [payload field, resource of the IRI] */
    private const array REFERENCES = [
        'defaultLocaleCode' => ['defaultLocale', Resource::Locale],
        'defaultTaxZoneCode' => ['defaultTaxZone', Resource::Zone],
        'menuTaxonCode' => ['menuTaxon', Resource::Taxon],
    ];

    /** item field => [payload field, resource of the IRIs] */
    private const array LISTS = [
        'currencyCodes' => ['currencies', Resource::Currency],
        'localeCodes' => ['locales', Resource::Locale],
        'countryCodes' => ['countries', Resource::Country],
    ];

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
        #[Schema(type: 'array', items: ChannelItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_channels', Route::collection(Resource::Channel), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'channel';
    }

    private function label(): string
    {
        return 'channels';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'hostname', 'baseCurrencyCode', 'defaultLocaleCode', 'defaultTaxZoneCode', 'menuTaxonCode', 'taxCalculationStrategy'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        foreach (['description', 'color', 'themeName', 'contactEmail'] as $field) {
            if (\array_key_exists($field, $item) && !\is_string($item[$field])) {
                return $field . ' is a string';
            }
        }
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
        }
        foreach (array_keys(self::LISTS) as $field) {
            if (\array_key_exists($field, $item) && (!\is_array($item[$field]) || [] !== array_filter($item[$field], static fn (mixed $c): bool => !\is_string($c) || '' === trim($c)))) {
                return $field . ' is a list of codes';
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: null}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (null !== $probe && (true === $probe['ok'] || 404 !== ($probe['status'] ?? null))) {
                continue;
            }
            $missing = array_values(array_filter(self::CREATE_FIELDS, static fn (string $field): bool => !\array_key_exists($field, $item)));
            if ([] !== $missing) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => $missing];
            }
        }

        return ['failures' => $failures, 'references' => null];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $lists = self::completedLists($item);
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                'name' => ['before' => null, 'after' => $item['name'] ?? null],
                'hostname' => ['before' => null, 'after' => $item['hostname'] ?? null],
                'baseCurrency' => ['before' => null, 'after' => $item['baseCurrencyCode'] ?? null],
                'defaultLocale' => ['before' => null, 'after' => $item['defaultLocaleCode'] ?? null],
                'currencies' => ['before' => null, 'after' => $lists['currencyCodes']],
                'locales' => ['before' => null, 'after' => $lists['localeCodes']],
                'enabled' => ['before' => null, 'after' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true],
            ];
            foreach (['defaultTaxZoneCode' => 'defaultTaxZone', 'menuTaxonCode' => 'menuTaxon', 'countryCodes' => 'countries'] as $field => $name) {
                if (\array_key_exists($field, $item)) {
                    $diff[$name] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        $diff = [];
        foreach (self::SCALARS as $field) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $before = 'enabled' === $field ? (bool) ($current[$field] ?? false) : ($current[$field] ?? null);
            if ($before !== $item[$field]) {
                $diff[$field] = ['before' => $before, 'after' => $item[$field]];
            }
        }
        foreach (self::REFERENCES as $field => [$name]) {
            if (\array_key_exists($field, $item)) {
                $before = AdminPayload::code($current[$name] ?? null);
                if ($before !== $item[$field]) {
                    $diff[$name] = ['before' => $before, 'after' => $item[$field]];
                }
            }
        }
        foreach (self::LISTS as $field => [$name]) {
            if (!\is_array($item[$field] ?? null)) {
                continue;
            }
            $before = AdminPayload::codes($current[$name] ?? []);
            $after = array_values(array_filter($item[$field], 'is_string'));
            $sortedBefore = $before;
            $sortedAfter = $after;
            sort($sortedBefore);
            sort($sortedAfter);
            if ($sortedBefore !== $sortedAfter) {
                $diff[$name] = ['before' => $before, 'after' => $after];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $lists = self::completedLists($item);
        $body = [
            'code' => $item['code'],
            'name' => $item['name'] ?? null,
            'hostname' => $item['hostname'] ?? null,
            'baseCurrency' => $this->iri(Resource::Currency, \is_string($item['baseCurrencyCode'] ?? null) ? $item['baseCurrencyCode'] : ''),
            'defaultLocale' => $this->iri(Resource::Locale, \is_string($item['defaultLocaleCode'] ?? null) ? $item['defaultLocaleCode'] : ''),
            'currencies' => $this->iris(Resource::Currency, $lists['currencyCodes']),
            'locales' => $this->iris(Resource::Locale, $lists['localeCodes']),
            'taxCalculationStrategy' => \is_string($item['taxCalculationStrategy'] ?? null) ? $item['taxCalculationStrategy'] : 'order_items_based',
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
        ];
        foreach (['defaultTaxZoneCode' => ['defaultTaxZone', Resource::Zone], 'menuTaxonCode' => ['menuTaxon', Resource::Taxon]] as $field => [$name, $resource]) {
            if (\is_string($item[$field] ?? null)) {
                $body[$name] = $this->iri($resource, $item[$field]);
            }
        }
        if (\is_array($item['countryCodes'] ?? null) && [] !== $item['countryCodes']) {
            $body['countries'] = $this->iris(Resource::Country, array_values(array_filter($item['countryCodes'], 'is_string')));
        }
        foreach (['description', 'color', 'themeName', 'contactEmail'] as $field) {
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
        foreach (self::REFERENCES as $field => [$name, $resource]) {
            if (\is_string($item[$field] ?? null)) {
                $body[$name] = $this->iri($resource, $item[$field]);
            }
        }
        foreach (self::LISTS as $field => [$name, $resource]) {
            if (\is_array($item[$field] ?? null)) {
                $body[$name] = $this->iris($resource, array_values(array_filter($item[$field], 'is_string')));
            }
        }

        return $body;
    }

    /**
     * The shop rejects a channel whose lists miss the base currency or the default locale.
     *
     * @param array<mixed> $item
     *
     * @return array{currencyCodes: list<string>, localeCodes: list<string>}
     */
    private static function completedLists(array $item): array
    {
        $out = [];
        foreach (['currencyCodes' => 'baseCurrencyCode', 'localeCodes' => 'defaultLocaleCode'] as $list => $anchor) {
            $codes = \is_array($item[$list] ?? null) ? array_values(array_filter($item[$list], 'is_string')) : [];
            $anchorCode = $item[$anchor] ?? null;
            if (\is_string($anchorCode) && !\in_array($anchorCode, $codes, true)) {
                $codes[] = $anchorCode;
            }
            $out[$list] = $codes;
        }

        return $out;
    }

    private function iri(Resource $resource, string $code): string
    {
        return $this->adapter->iri($resource, $code);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function iris(Resource $resource, array $codes): array
    {
        return array_map(fn (string $code): string => $this->iri($resource, $code), $codes);
    }
}
