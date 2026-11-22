<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Promotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Promotion\ConfiguredElements;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\PromotionConfigurationTrait;
use Emagma\McpSyliusBundle\Tool\Shared\SortablePositionNotice;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates promotions in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_promotions',
    description: 'Create or update CART promotions in one call, at most 50 items, matched on their code (for'
        . ' discounts on product prices use upsert_catalog_promotions). A creation needs name; label targets'
        . ' the item locale and a creation copies it to every enabled locale. channels, rules and actions'
        . ' replace their whole list when given ([] clears rules or actions). mode: upsert (default),'
        . ' create (already_exists on an existing code) or update (not_found on an unknown one). An item'
        . ' that changes nothing is unchanged and not written. One confirmation for the batch; one result'
        . ' per item. Tool-specific reasons: fields_required.'
        . SortablePositionNotice::PRIORITY,
)]
#[Permission(resource: 'promotion', action: Action::Write)]
final readonly class UpsertPromotions
{
    use BatchUpsertTrait;
    use PromotionConfigurationTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    private const array SCALARS = ['name', 'description', 'priority', 'exclusive', 'appliesToDiscounted', 'couponBased', 'usageLimit', 'startsAt', 'endsAt'];

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
        #[Schema(type: 'array', items: PromotionItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_promotions', Route::collection(Resource::Promotion), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'promotion';
    }

    private function label(): string
    {
        return 'cart promotions';
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
        foreach (['exclusive', 'appliesToDiscounted', 'couponBased'] as $field) {
            if (\array_key_exists($field, $item) && !\is_bool($item[$field])) {
                return $field . ' is a boolean';
            }
        }
        if (\array_key_exists('priority', $item) && !\is_int($item['priority'])) {
            return 'priority is an integer';
        }
        if (\array_key_exists('usageLimit', $item) && null !== $item['usageLimit'] && !\is_int($item['usageLimit'])) {
            return 'usageLimit is an integer or null';
        }
        foreach (['startsAt', 'endsAt'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field] && !\is_string($item[$field])) {
                return $field . ' is an ISO-8601 string or null';
            }
        }
        if (\array_key_exists('channels', $item) && (!\is_array($item['channels']) || [] !== array_filter($item['channels'], static fn (mixed $c): bool => !\is_string($c) || '' === $c))) {
            return 'channels is a list of channel codes';
        }
        foreach (['rules', 'actions'] as $field) {
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
            $isCreation = null === $probe || (true !== $probe['ok'] && 404 === ($probe['status'] ?? null));
            if ($isCreation && !\array_key_exists('name', $item)) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['name']];
            }
        }

        return ['failures' => $failures, 'references' => ['locales' => $this->enabledLocales($this->client, $this->defaultLocale)]];
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
            foreach (['exclusive' => false, 'appliesToDiscounted' => true, 'couponBased' => false] as $field => $default) {
                $diff[$field] ??= ['before' => null, 'after' => $default];
            }
            if (\is_array($item['channels'] ?? null)) {
                $diff['channels'] = ['before' => null, 'after' => array_values($item['channels'])];
            }
            foreach (['rules', 'actions'] as $field) {
                $normalized = self::normalizeConfigured($item[$field] ?? null);
                if (null !== $normalized) {
                    $diff[$field] = ['before' => null, 'after' => $normalized];
                }
            }
            if (\is_string($item['label'] ?? null)) {
                $diff[$locale . '.label'] = ['before' => null, 'after' => trim($item['label'])];
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
        foreach (['rules', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized && ConfiguredElements::fromPayload($current[$field] ?? null) !== $normalized) {
                $diff[$field] = ['before' => ConfiguredElements::fromPayload($current[$field] ?? null), 'after' => $normalized];
            }
        }
        if (\is_string($item['label'] ?? null)) {
            $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $before = \is_string($translation['label'] ?? null) ? $translation['label'] : null;
            if ($before !== trim($item['label'])) {
                $diff[$locale . '.label'] = ['before' => $before, 'after' => trim($item['label'])];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = [
            'code' => $item['code'],
            'name' => $item['name'] ?? null,
            'exclusive' => $item['exclusive'] ?? false,
            'appliesToDiscounted' => $item['appliesToDiscounted'] ?? true,
            'couponBased' => $item['couponBased'] ?? false,
        ];
        foreach (['description', 'startsAt', 'endsAt', 'priority', 'usageLimit'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field] && '' !== $item[$field]) {
                $body[$field] = $item[$field];
            }
        }
        if (\is_array($item['channels'] ?? null)) {
            $body['channels'] = $this->channelIris($item['channels']);
        }
        foreach (['rules', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized) {
                $body[$field] = $normalized;
            }
        }
        if (\is_string($item['label'] ?? null)) {
            $locale = $this->localeOf($item);
            $locales = \is_array($references) && \is_array($references['locales'] ?? null) ? array_values(array_filter($references['locales'], 'is_string')) : [$locale];
            if (!\in_array($locale, $locales, true)) {
                $locales = [$locale, ...$locales];
            }
            $body['translations'] = self::fillTranslations($locales, ['label' => trim($item['label'])]);
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
        if (\is_array($item['channels'] ?? null)) {
            $body['channels'] = $this->channelIris($item['channels']);
        }
        foreach (['rules', 'actions'] as $field) {
            $normalized = self::normalizeConfigured($item[$field] ?? null);
            if (null !== $normalized) {
                $body[$field] = $normalized;
            }
        }
        if (\is_string($item['label'] ?? null)) {
            $locale = $this->localeOf($item);
            $translations = \is_array($body['translations'] ?? null) ? $body['translations'] : [];
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $translation['label'] = trim($item['label']);
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
