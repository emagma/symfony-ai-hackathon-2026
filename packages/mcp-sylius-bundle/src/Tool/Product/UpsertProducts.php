<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Product;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates products in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_products',
    description: 'Create or update products in one call, at most 50 items, matched on their code. A creation'
        . ' needs name and slug and copies the translated fields to every enabled locale; on an update they'
        . ' target the item locale. mainTaxonCode null or "" detaches; channelCodes, optionCodes and'
        . ' attributes replace their whole collection when given. Secondary taxons are read-only here'
        . ' (update_product_taxons). mode: upsert (default), create (already_exists on an existing code) or'
        . ' update (not_found on an unknown one). An item that changes nothing is unchanged and not written.'
        . ' One confirmation for the batch; one result per item. Tool-specific reasons: fields_required,'
        . ' main_taxon_not_found, channel_not_found, option_not_found, product_attribute_not_found,'
        . ' options_locked_by_variants. Order for a product with variants: upsert_product_options, then'
        . ' this tool with optionCodes and channelCodes, then upsert_product_variants.',
)]
#[Permission(resource: 'product', action: Action::Write)]
final readonly class UpsertProducts
{
    use BatchUpsertTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    private const array TRANSLATED = ['name', 'slug', 'description', 'shortDescription', 'metaKeywords', 'metaDescription'];

    private const array CREATE_FIELDS = ['name', 'slug'];

    public function __construct(
        private AdminApiClientInterface $client,
        private ReferenceResolver $references,
        private WriteJournalInterface $audit,
        private ProductUpdatePayloadBuilder $payload,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ProductItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_products', Route::collection(Resource::Product), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product';
    }

    private function label(): string
    {
        return 'products';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'slug', 'locale'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        foreach (['description', 'shortDescription', 'metaKeywords', 'metaDescription'] as $field) {
            if (\array_key_exists($field, $item) && !\is_string($item[$field])) {
                return $field . ' is a string';
            }
        }
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
        }
        if (\array_key_exists('mainTaxonCode', $item) && null !== $item['mainTaxonCode'] && !\is_string($item['mainTaxonCode'])) {
            return 'mainTaxonCode is a string, or null to detach';
        }
        foreach (['channelCodes', 'optionCodes'] as $field) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            if (!\is_array($item[$field])) {
                return $field . ' is a list of codes';
            }
            foreach ($item[$field] as $code) {
                if (!\is_string($code) || '' === trim($code)) {
                    return $field . ' is a list of codes';
                }
            }
        }
        if (\array_key_exists('attributes', $item)) {
            if (!\is_array($item['attributes'])) {
                return 'attributes is a list of {code, locale, value}';
            }
            foreach ($item['attributes'] as $attribute) {
                if (!\is_array($attribute) || !\is_string($attribute['code'] ?? null) || '' === $attribute['code'] ||
                    !\is_string($attribute['locale'] ?? null) || '' === $attribute['locale'] ||
                    !\array_key_exists('value', $attribute)) {
                    return 'attributes is a list of {code, locale, value}';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array{locales: list<string>, known: array<string, array<string, string>>}}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $wanted = [Resource::Taxon->value => [], Resource::Channel->value => [], Resource::ProductOption->value => [], Resource::ProductAttribute->value => []];
        foreach ($itemsByKey as $item) {
            $requested = self::requested($item);
            if (\is_string($requested['mainTaxonCode']) && '' !== $requested['mainTaxonCode']) {
                $wanted[Resource::Taxon->value][] = $requested['mainTaxonCode'];
            }
            array_push($wanted[Resource::Channel->value], ...($requested['channelCodes'] ?? []));
            array_push($wanted[Resource::ProductOption->value], ...($requested['optionCodes'] ?? []));
            foreach ($requested['attributes'] ?? [] as $attribute) {
                $wanted[Resource::ProductAttribute->value][] = $attribute['code'];
            }
        }
        $known = [] === array_filter($wanted) ? [] : $this->references->lookupAll(array_filter($wanted));

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (null === $probe || (true !== $probe['ok'] && 404 === ($probe['status'] ?? null))) {
                $missing = array_values(array_filter(self::CREATE_FIELDS, static fn (string $field): bool => !\array_key_exists($field, $item)));
                if ([] !== $missing) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => $missing];

                    continue;
                }
            }
            $requested = self::requested($item);
            $mainTaxonCode = $requested['mainTaxonCode'];
            if (\is_string($mainTaxonCode) && '' !== $mainTaxonCode && !isset($known[Resource::Taxon->value][$mainTaxonCode])) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'main_taxon_not_found', 'mainTaxonCode' => $mainTaxonCode];

                continue;
            }
            foreach ($requested['channelCodes'] ?? [] as $channelCode) {
                if (!isset($known[Resource::Channel->value][$channelCode])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'channel_not_found', 'channelCode' => $channelCode];

                    continue 2;
                }
            }
            foreach ($requested['optionCodes'] ?? [] as $optionCode) {
                if (!isset($known[Resource::ProductOption->value][$optionCode])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'option_not_found', 'optionCode' => $optionCode];

                    continue 2;
                }
            }
            // Sylius silently drops `options` from a PUT on a product that has variants.
            if (null !== $requested['optionCodes'] && null !== $probe && true === $probe['ok']) {
                $variantsCount = \count(AdminPayload::strings($probe['body']['variants'] ?? []));
                $currentOptions = AdminPayload::codes($probe['body']['options'] ?? []);
                $wantedOptions = $requested['optionCodes'];
                sort($currentOptions);
                sort($wantedOptions);
                if (0 < $variantsCount && $currentOptions !== $wantedOptions) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'options_locked_by_variants', 'optionCodes' => $currentOptions, 'variantsCount' => $variantsCount];
                }
            }
            foreach ($requested['attributes'] ?? [] as $attribute) {
                if (!isset($known[Resource::ProductAttribute->value][$attribute['code']])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'product_attribute_not_found', 'attributeCode' => $attribute['code']];

                    continue 2;
                }
            }
        }

        return ['failures' => $failures, 'references' => [
            'locales' => $this->enabledLocales($this->client, $this->defaultLocale),
            'known' => $known,
        ]];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $requested = self::requested($item);
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            foreach (self::TRANSLATED as $field) {
                if (null !== $requested[$field]) {
                    $diff[$locale . '.' . $field] = ['before' => null, 'after' => $requested[$field]];
                }
            }
            $diff['enabled'] = ['before' => null, 'after' => $requested['enabled'] ?? true];
            if (\is_string($requested['mainTaxonCode']) && '' !== $requested['mainTaxonCode']) {
                $diff['mainTaxon'] = ['before' => null, 'after' => $requested['mainTaxonCode']];
            }
            if (null !== $requested['channelCodes']) {
                $diff['channels'] = ['before' => null, 'after' => $requested['channelCodes']];
            }
            if (null !== $requested['optionCodes']) {
                $diff['options'] = ['before' => null, 'after' => $requested['optionCodes']];
            }
            if (null !== $requested['attributes']) {
                $diff['attributes'] = ['before' => null, 'after' => $requested['attributes']];
            }

            return $diff;
        }

        return $this->payload->computeDiff($current, $locale, $requested);
    }

    private function postBody(array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $requested = self::requested($item);
        $locales = \is_array($references) && \is_array($references['locales'] ?? null)
            ? array_values(array_filter($references['locales'], 'is_string'))
            : [$locale];
        if (!\in_array($locale, $locales, true)) {
            $locales = [$locale, ...$locales];
        }
        $translation = [];
        foreach (self::TRANSLATED as $field) {
            if (null !== $requested[$field]) {
                $translation[$field] = $requested[$field];
            }
        }
        $base = [
            'code' => $item['code'],
            'translations' => self::fillTranslations($locales, $translation),
            'enabled' => $requested['enabled'] ?? true,
        ];
        foreach (self::TRANSLATED as $field) {
            $requested[$field] = null;
        }
        $requested['enabled'] = null;

        return $this->payload->buildPutBody($base, $locale, $requested, $this->irisFor($requested, $references));
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $requested = self::requested($item);

        return $this->payload->buildPutBody($current, $this->localeOf($item), $requested, $this->irisFor($requested, $references));
    }

    /**
     * Null for every field the item does not carry; mainTaxonCode "" when the item detaches.     *
     *
     * @param array<mixed> $item
     *
     * @return array{name: ?string, slug: ?string, description: ?string, shortDescription: ?string, metaKeywords: ?string, metaDescription: ?string, enabled: ?bool, mainTaxonCode: ?string, channelCodes: list<string>|null, optionCodes: list<string>|null, attributes: list<array{code: string, locale: string, value: mixed}>|null}
     */
    private static function requested(array $item): array
    {
        $requested = [];
        foreach (self::TRANSLATED as $field) {
            $requested[$field] = \is_string($item[$field] ?? null) ? $item[$field] : null;
        }
        $requested['enabled'] = \is_bool($item['enabled'] ?? null) ? $item['enabled'] : null;
        $requested['mainTaxonCode'] = \array_key_exists('mainTaxonCode', $item)
            ? (\is_string($item['mainTaxonCode']) ? $item['mainTaxonCode'] : '')
            : null;
        foreach (['channelCodes', 'optionCodes'] as $field) {
            $requested[$field] = \is_array($item[$field] ?? null) ? array_values(array_filter($item[$field], 'is_string')) : null;
        }
        $attributes = null;
        if (\is_array($item['attributes'] ?? null)) {
            $attributes = [];
            foreach ($item['attributes'] as $attribute) {
                if (\is_array($attribute) && \is_string($attribute['code'] ?? null) && \is_string($attribute['locale'] ?? null)) {
                    $attributes[] = ['code' => $attribute['code'], 'locale' => $attribute['locale'], 'value' => $attribute['value'] ?? null];
                }
            }
        }
        $requested['attributes'] = $attributes;

        /** @var array{name: ?string, slug: ?string, description: ?string, shortDescription: ?string, metaKeywords: ?string, metaDescription: ?string, enabled: ?bool, mainTaxonCode: ?string, channelCodes: list<string>|null, optionCodes: list<string>|null, attributes: list<array{code: string, locale: string, value: mixed}>|null} $shaped */
        $shaped = $requested;

        return $shaped;
    }

    /**
     * @param array{mainTaxonCode: ?string, channelCodes: list<string>|null, optionCodes: list<string>|null, attributes: list<array{code: string, locale: string, value: mixed}>|null} $requested
     *
     * @return array{mainTaxonIri?: string|null, channelIris?: list<string>, optionIris?: list<string>, attributeIris?: array<string, string>}
     */
    private function irisFor(array $requested, mixed $references): array
    {
        /** @var array<string, array<string, string>> $known */
        $known = \is_array($references) && \is_array($references['known'] ?? null) ? $references['known'] : [];
        $iris = [];
        if (\is_string($requested['mainTaxonCode']) && '' !== $requested['mainTaxonCode']) {
            $iris['mainTaxonIri'] = $known[Resource::Taxon->value][$requested['mainTaxonCode']] ?? null;
        }
        if (null !== $requested['channelCodes']) {
            $iris['channelIris'] = array_values(array_intersect_key($known[Resource::Channel->value] ?? [], array_flip($requested['channelCodes'])));
        }
        if (null !== $requested['optionCodes']) {
            $iris['optionIris'] = array_values(array_intersect_key($known[Resource::ProductOption->value] ?? [], array_flip($requested['optionCodes'])));
        }
        if (null !== $requested['attributes']) {
            $iris['attributeIris'] = $known[Resource::ProductAttribute->value] ?? [];
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
