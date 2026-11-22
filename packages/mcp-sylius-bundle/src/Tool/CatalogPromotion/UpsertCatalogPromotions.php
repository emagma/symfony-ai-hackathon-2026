<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CatalogPromotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\AdminApi\ViolationExtractor;
use Emagma\McpSyliusBundle\Output\Promotion\ConfiguredElements;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\PromotionConfigurationTrait;
use Emagma\McpSyliusBundle\Tool\Shared\SortablePositionNotice;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Sylius refuses every write on a promotion whose price recalculation is queued (`state: processing`):
 * the probe tells; when the state moves before the PUT, the shop's 400 maps to the same reason.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_catalog_promotions',
    description: 'Create or update CATALOG promotions (discounts on product prices) in one call, at most 50'
        . ' items, matched on their code. A creation needs name and is disabled unless enabled: true, which'
        . ' puts the discount live once Sylius finishes processing. label and description target the item'
        . ' locale; channels, scopes and actions replace their whole list when given ([] clears). A'
        . ' promotion in state processing refuses every write: wait for it. mode: upsert (default), create'
        . ' (already_exists on an existing code) or update (not_found on an unknown one). An item that'
        . ' changes nothing is unchanged and not written. One confirmation for the batch; one result per'
        . ' item. Tool-specific reasons: fields_required, processing.'
        . SortablePositionNotice::PRIORITY,
)]
#[Permission(resource: 'catalog_promotion', action: Action::Write)]
final readonly class UpsertCatalogPromotions
{
    use BatchUpsertTrait;
    use PromotionConfigurationTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    public const string PROCESSING_HINT = 'The promotion is still being processed and refuses any write. Retry once its state is'
        . ' no longer "processing"; if it never changes, the shop\'s messenger worker is not consuming the'
        . ' catalog promotion queue.';

    private const array SCALARS = ['name', 'priority', 'exclusive', 'startDate', 'endDate', 'enabled'];

    private const array TRANSLATED = ['label', 'description'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: CatalogPromotionItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_catalog_promotions', Route::collection(Resource::CatalogPromotion), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'catalog_promotion';
    }

    private function label(): string
    {
        return 'catalog promotions';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'locale', 'label'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        if (\array_key_exists('description', $item) && !\is_string($item['description'])) {
            return 'description is a string';
        }
        foreach (['exclusive', 'enabled'] as $field) {
            if (\array_key_exists($field, $item) && !\is_bool($item[$field])) {
                return $field . ' is a boolean';
            }
        }
        if (\array_key_exists('priority', $item) && !\is_int($item['priority'])) {
            return 'priority is an integer';
        }
        foreach (['startDate', 'endDate'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field] && !\is_string($item[$field])) {
                return $field . ' is an ISO-8601 string or null';
            }
        }
        if (\array_key_exists('channels', $item) && (!\is_array($item['channels']) || [] !== array_filter($item['channels'], static fn (mixed $c): bool => !\is_string($c) || '' === $c))) {
            return 'channels is a list of channel codes';
        }
        foreach (['scopes', 'actions'] as $field) {
            if (\array_key_exists($field, $item) && !\is_array($item[$field])) {
                return $field . ' is a list of {type, configuration}';
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array{locales: list<string>}}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (null !== $probe && true === $probe['ok']) {
                if ('processing' === ($probe['body']['state'] ?? null)) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'processing', 'hint' => self::PROCESSING_HINT];
                }

                continue;
            }
            if ((null === $probe || 404 === ($probe['status'] ?? null)) && !\array_key_exists('name', $item)) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['name']];
            }
        }

        return ['failures' => $failures, 'references' => ['locales' => $this->enabledLocales($this->client, $this->defaultLocale)]];
    }

    /**
     * @param array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null} $outcome
     * @param array<string, array{before: mixed, after: mixed}>                                      $diff
     *
     * @return array<string, mixed>
     */
    private function writeFailure(array $outcome, array $diff): array
    {
        if (400 === $outcome['status']) {
            return ['ok' => false, 'action' => 'failed', 'reason' => 'processing', 'errors' => ViolationExtractor::fromPayload($outcome['body'] ?? null), 'hint' => self::PROCESSING_HINT, 'diff' => $diff];
        }

        return ApiFailure::fromResult($outcome, ['action' => 'failed', 'diff' => $diff]);
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            foreach (self::SCALARS as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }
            foreach (['exclusive' => false, 'enabled' => false] as $field => $default) {
                $diff[$field] ??= ['before' => null, 'after' => $default];
            }
            $diff['channels'] = ['before' => null, 'after' => \is_array($item['channels'] ?? null) ? array_values($item['channels']) : []];
            foreach (['scopes', 'actions'] as $field) {
                $normalized = self::normalizeConfigured($item[$field] ?? null);
                if (null !== $normalized) {
                    $diff[$field] = ['before' => null, 'after' => $normalized];
                }
            }
            foreach (self::TRANSLATED as $field) {
                if (\is_string($item[$field] ?? null) && '' !== trim($item[$field])) {
                    $diff[$locale . '.' . $field] = ['before' => null, 'after' => trim($item[$field])];
                }
            }

            return $diff;
        }

        $diff = [];
        foreach (self::SCALARS as $field) {
            if (\array_key_exists($field, $item) && ($current[$field] ?? null) !== $item[$field]) {
                $diff[$field] = ['before' => $current[$field] ?? null, 'after' => $item[$field]];
            }
        }
        if (\is_array($item['channels'] ?? null)) {
            $before = AdminPayload::codes($current['channels'] ?? []);
            $after = array_values(array_filter($item['channels'], 'is_string'));
            if ($before !== $after) {
                $diff['channels'] = ['before' => $before, 'after' => $after];
            }
        }
        foreach (['scopes', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized && ConfiguredElements::fromPayload($current[$field] ?? null) !== $normalized) {
                $diff[$field] = ['before' => ConfiguredElements::fromPayload($current[$field] ?? null), 'after' => $normalized];
            }
        }
        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
        foreach (self::TRANSLATED as $field) {
            if (\is_string($item[$field] ?? null)) {
                $before = \is_string($translation[$field] ?? null) ? $translation[$field] : null;
                if ($before !== trim($item[$field])) {
                    $diff[$locale . '.' . $field] = ['before' => $before, 'after' => trim($item[$field])];
                }
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = [
            'code' => $item['code'],
            'name' => $item['name'] ?? null,
            'enabled' => $item['enabled'] ?? false,
            'exclusive' => $item['exclusive'] ?? false,
            'channels' => $this->channelIris(\is_array($item['channels'] ?? null) ? $item['channels'] : []),
        ];
        if (\is_int($item['priority'] ?? null)) {
            $body['priority'] = $item['priority'];
        }
        foreach (['startDate', 'endDate'] as $field) {
            if (\is_string($item[$field] ?? null) && '' !== $item[$field]) {
                $body[$field] = $item[$field];
            }
        }
        foreach (['scopes', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized) {
                $body[$field] = $normalized;
            }
        }
        $translation = [];
        foreach (self::TRANSLATED as $field) {
            if (\is_string($item[$field] ?? null) && '' !== trim($item[$field])) {
                $translation[$field] = trim($item[$field]);
            }
        }
        if ([] !== $translation) {
            $locale = $this->localeOf($item);
            $locales = \is_array($references) && \is_array($references['locales'] ?? null) ? array_values(array_filter($references['locales'], 'is_string')) : [$locale];
            if (!\in_array($locale, $locales, true)) {
                $locales = [$locale, ...$locales];
            }
            $body['translations'] = self::fillTranslations($locales, $translation);
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        // `state` is computed and read-only.
        unset($body['state']);
        foreach (self::SCALARS as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }
        if (\is_array($item['channels'] ?? null)) {
            $body['channels'] = $this->channelIris($item['channels']);
        }
        foreach (['scopes', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized) {
                $body[$field] = $normalized;
            }
        }
        $locale = $this->localeOf($item);
        $translations = \is_array($body['translations'] ?? null) ? $body['translations'] : [];
        $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
        $translated = false;
        foreach (self::TRANSLATED as $field) {
            if (\is_string($item[$field] ?? null)) {
                $translation[$field] = trim($item[$field]);
                $translated = true;
            }
        }
        if ($translated) {
            if (!isset($translation['@id'])) {
                $translation['locale'] = $locale;
            }
            $translations[$locale] = $translation;
            $body['translations'] = $translations;
        }

        return $body;
    }

    /**
     * @param array<mixed> $channels
     *
     * @return list<string>
     */
    private function channelIris(array $channels): array
    {
        $iris = [];
        foreach ($channels as $channel) {
            if (\is_string($channel) && '' !== $channel) {
                $iris[] = $this->adapter->iri(Resource::Channel, $channel);
            }
        }

        return $iris;
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
