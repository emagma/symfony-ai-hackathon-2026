<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductVariant;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\ProductVariant\ProductVariantOutput;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * `pricing` is merged, not replaced whole: a variant must keep a price in every channel.
 */
#[McpTool(
    name: 'upsert_product_variants',
    description: 'Create or update product variants in one call, at most 50 items, matched on their code. A'
        . ' creation needs productCode (immutable afterwards); Sylius then wants optionValues for every'
        . ' product option and pricing for every product channel. The product must list its options'
        . ' (upsert_products optionCodes) BEFORE its first variant: Sylius freezes them afterwards. On an update optionValues replaces the'
        . ' set while pricing and originalPricing (the struck-through price) are merged (omitted channels'
        . ' keep theirs). Set name and taxCategoryCode'
        . ' for a shop-ready variant. mode: upsert (default), create (already_exists on an existing code)'
        . ' or update (not_found on an unknown one). An item that changes nothing is unchanged and not'
        . ' written. One confirmation for the batch; one result per item. Tool-specific reasons:'
        . ' fields_required, product_not_found, product_immutable, tax_category_not_found,'
        . ' option_value_not_found (the valid value codes: get_product_options).',
)]
#[Permission(resource: 'product_variant', action: Action::Write)]
final readonly class UpsertProductVariants
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const array SCALARS = ['enabled', 'tracked', 'onHand', 'weight', 'shippingRequired'];

    public function __construct(
        private AdminApiClientInterface $client,
        private ReferenceResolver $references,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ProductVariantItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_product_variants', Route::collection(Resource::ProductVariant), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_variant';
    }

    private function label(): string
    {
        return 'product variants';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['productCode', 'locale', 'name', 'taxCategoryCode'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        foreach (['enabled', 'tracked', 'shippingRequired'] as $field) {
            if (\array_key_exists($field, $item) && !\is_bool($item[$field])) {
                return $field . ' is a boolean';
            }
        }
        if (\array_key_exists('onHand', $item) && !\is_int($item['onHand'])) {
            return 'onHand is an integer';
        }
        if (\array_key_exists('weight', $item) && !\is_int($item['weight']) && !\is_float($item['weight'])) {
            return 'weight is a number';
        }
        if (\array_key_exists('optionValues', $item)) {
            if (!\is_array($item['optionValues'])) {
                return 'optionValues is a {optionCode: valueCode} map';
            }
            foreach ($item['optionValues'] as $optionCode => $valueCode) {
                if (!\is_string($valueCode) && !\is_int($valueCode)) {
                    return 'optionValues is a {optionCode: valueCode} map of codes';
                }
                if ('' === trim((string) $optionCode) || '' === trim((string) $valueCode)) {
                    return 'optionValues is a {optionCode: valueCode} map of codes';
                }
            }
        }
        if (\array_key_exists('pricing', $item)) {
            if (!\is_array($item['pricing'])) {
                return 'pricing is a {channelCode: price} map';
            }
            foreach ($item['pricing'] as $channelCode => $price) {
                if ('' === trim((string) $channelCode) || !\is_int($price)) {
                    return 'pricing is a {channelCode: price} map of integers (minor units)';
                }
            }
        }
        if (\array_key_exists('originalPricing', $item)) {
            if (!\is_array($item['originalPricing'])) {
                return 'originalPricing is a {channelCode: originalPrice} map';
            }
            foreach ($item['originalPricing'] as $channelCode => $price) {
                if ('' === trim((string) $channelCode) || (null !== $price && !\is_int($price))) {
                    return 'originalPricing is a {channelCode: originalPrice} map of integers (minor units) or null';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array<string, array<string, string>>}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $wanted = [];
        foreach ($itemsByKey as $key => $item) {
            if (\is_string($item['productCode'] ?? null) && !self::exists($probes[$key] ?? null)) {
                $wanted[Resource::Product->value][] = $item['productCode'];
            }
            if (\is_string($item['taxCategoryCode'] ?? null)) {
                $wanted[Resource::TaxCategory->value][] = $item['taxCategoryCode'];
            }
            foreach (self::optionValuesOf($item) as $optionCode => $valueCode) {
                $wanted[self::valuesOf($optionCode)][] = $valueCode;
            }
        }
        $known = [] === $wanted ? [] : $this->references->lookupAll($wanted);

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            $productCode = \is_string($item['productCode'] ?? null) ? $item['productCode'] : null;
            if (self::exists($probe)) {
                $currentProduct = AdminPayload::code($probe['body']['product'] ?? null);
                if (null !== $productCode && $productCode !== $currentProduct) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'product_immutable', 'productCode' => $currentProduct];

                    continue;
                }
            } elseif (null === $probe || 404 === ($probe['status'] ?? null)) {
                if (null === $productCode) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['productCode']];

                    continue;
                }
                if (!isset($known[Resource::Product->value][$productCode])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'product_not_found', 'productCode' => $productCode];

                    continue;
                }
            }
            $taxCategoryCode = $item['taxCategoryCode'] ?? null;
            if (\is_string($taxCategoryCode) && !isset($known[Resource::TaxCategory->value][$taxCategoryCode])) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'tax_category_not_found', 'taxCategoryCode' => $taxCategoryCode];

                continue;
            }
            foreach (self::optionValuesOf($item) as $optionCode => $valueCode) {
                if (!isset($known[self::valuesOf($optionCode)][$valueCode])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'option_value_not_found', 'optionCode' => $optionCode, 'valueCode' => $valueCode];

                    continue 2;
                }
            }
        }

        return ['failures' => $failures, 'references' => $known];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        if (null === $current) {
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                'product' => ['before' => null, 'after' => $item['productCode'] ?? null],
            ];
            if (\array_key_exists('taxCategoryCode', $item)) {
                $diff['taxCategory'] = ['before' => null, 'after' => $item['taxCategoryCode']];
            }
            if (\array_key_exists('name', $item)) {
                $diff[$locale . '.name'] = ['before' => null, 'after' => $item['name']];
            }
            foreach (['optionValues', 'pricing', 'originalPricing'] as $field) {
                if (\is_array($item[$field] ?? null) && [] !== $item[$field]) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }
            foreach (self::SCALARS as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        $before = ProductVariantOutput::fromPayload($current, $locale, AdminPayload::text($current['code'] ?? null));
        $diff = [];
        if (\is_string($item['name'] ?? null) && $item['name'] !== $before->name) {
            $diff[$locale . '.name'] = ['before' => $before->name, 'after' => $item['name']];
        }
        $currentScalars = [
            'enabled' => $before->enabled,
            'tracked' => $before->tracked,
            'onHand' => $before->onHand,
            'weight' => $before->weight,
            'shippingRequired' => $before->shippingRequired,
        ];
        foreach ($currentScalars as $field => $currentValue) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $after = 'weight' === $field && \is_int($item[$field]) ? (float) $item[$field] : $item[$field];
            if ($after !== $currentValue) {
                $diff[$field] = ['before' => $currentValue, 'after' => $item[$field]];
            }
        }
        if (\is_string($item['taxCategoryCode'] ?? null) && $item['taxCategoryCode'] !== $before->taxCategoryCode) {
            $diff['taxCategory'] = ['before' => $before->taxCategoryCode, 'after' => $item['taxCategoryCode']];
        }
        if (\is_array($item['optionValues'] ?? null)) {
            $after = array_values(self::optionValuesOf($item));
            $sortedBefore = $before->optionValueCodes;
            sort($sortedBefore);
            $sortedAfter = $after;
            sort($sortedAfter);
            if ($sortedBefore !== $sortedAfter) {
                $diff['optionValues'] = ['before' => $before->optionValueCodes, 'after' => $item['optionValues']];
            }
        }
        if (\is_array($item['pricing'] ?? null)) {
            foreach ($item['pricing'] as $channelCode => $price) {
                if (($before->pricing[(string) $channelCode] ?? null) !== $price) {
                    $diff['pricing'] = ['before' => $before->pricing, 'after' => $item['pricing']];

                    break;
                }
            }
        }
        if (\is_array($item['originalPricing'] ?? null)) {
            foreach ($item['originalPricing'] as $channelCode => $price) {
                if (($before->originalPricing[(string) $channelCode] ?? null) !== $price) {
                    $diff['originalPricing'] = ['before' => $before->originalPricing, 'after' => $item['originalPricing']];

                    break;
                }
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $known = self::knownOf($references);
        $productCode = \is_string($item['productCode'] ?? null) ? $item['productCode'] : '';
        $body = [
            'code' => $item['code'],
            'product' => $known[Resource::Product->value][$productCode] ?? null,
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
        ];
        if (\is_string($item['taxCategoryCode'] ?? null)) {
            $body['taxCategory'] = $known[Resource::TaxCategory->value][$item['taxCategoryCode']] ?? null;
        }
        if (\is_string($item['name'] ?? null)) {
            $body['translations'] = [$this->localeOf($item) => ['name' => $item['name']]];
        }
        if (\is_array($item['optionValues'] ?? null) && [] !== $item['optionValues']) {
            $body['optionValues'] = self::optionValueIris(self::optionValuesOf($item), $known);
        }
        $channelPricings = self::mergePricing(null, $item);
        if ([] !== $channelPricings) {
            $body['channelPricings'] = $channelPricings;
        }
        foreach (['tracked', 'onHand', 'weight', 'shippingRequired'] as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $known = self::knownOf($references);
        $body = $current;
        if (\is_string($item['taxCategoryCode'] ?? null)) {
            $body['taxCategory'] = $known[Resource::TaxCategory->value][$item['taxCategoryCode']] ?? null;
        }
        if (\is_string($item['name'] ?? null)) {
            $locale = $this->localeOf($item);
            $translations = \is_array($body['translations'] ?? null) ? $body['translations'] : [];
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $translation['name'] = $item['name'];
            $translations[$locale] = $translation;
            $body['translations'] = $translations;
        }
        foreach (self::SCALARS as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }
        if (\is_array($item['optionValues'] ?? null)) {
            $body['optionValues'] = self::optionValueIris(self::optionValuesOf($item), $known);
        }
        if (\is_array($item['pricing'] ?? null) || \is_array($item['originalPricing'] ?? null)) {
            $body['channelPricings'] = self::mergePricing($current['channelPricings'] ?? null, $item);
        }

        return $body;
    }

    /**
     * @param array<string, string>                $optionValues
     * @param array<string, array<string, string>> $known
     *
     * @return list<string>
     */
    private static function optionValueIris(array $optionValues, array $known): array
    {
        $iris = [];
        foreach ($optionValues as $optionCode => $valueCode) {
            $iri = $known[self::valuesOf($optionCode)][$valueCode] ?? null;
            if (\is_string($iri)) {
                $iris[] = $iri;
            }
        }

        return $iris;
    }

    /**
     * Both as strings: a numeric value code decodes to an integer key or value.     *
     *
     * @param array<mixed> $item
     *
     * @return array<string, string>
     */
    private static function optionValuesOf(array $item): array
    {
        $out = [];
        foreach (\is_array($item['optionValues'] ?? null) ? $item['optionValues'] : [] as $optionCode => $valueCode) {
            if (\is_string($valueCode) || \is_int($valueCode)) {
                $out[(string) $optionCode] = (string) $valueCode;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function knownOf(mixed $references): array
    {
        /** @var array<string, array<string, string>> $known */
        $known = \is_array($references) ? $references : [];

        return $known;
    }

    /**
     * A current entry is kept whole, `@id` included: rebuilt without it, the PUT creates a second
     * pricing for the channel, which Sylius rejects.     *
     *
     * @param array<mixed> $item
     *
     * @return array<string, array<mixed>>
     */
    private static function mergePricing(mixed $currentPricings, array $item): array
    {
        $merged = [];
        if (\is_array($currentPricings)) {
            foreach ($currentPricings as $key => $entry) {
                if (\is_array($entry)) {
                    $merged[\is_string($entry['channelCode'] ?? null) ? $entry['channelCode'] : (string) $key] = $entry;
                }
            }
        }
        foreach (['pricing' => 'price', 'originalPricing' => 'originalPrice'] as $field => $property) {
            foreach (\is_array($item[$field] ?? null) ? $item[$field] : [] as $channelCode => $price) {
                if (\is_int($price) || ('originalPrice' === $property && null === $price)) {
                    $channelCode = (string) $channelCode;
                    $merged[$channelCode] ??= ['channelCode' => $channelCode];
                    $merged[$channelCode][$property] = $price;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}|null $probe
     *
     * @phpstan-assert-if-true array{ok: true, body: array<string, mixed>} $probe
     */
    private static function exists(?array $probe): bool
    {
        return null !== $probe && true === $probe['ok'];
    }

    private static function valuesOf(string $optionCode): string
    {
        return ReferenceResolver::key(Resource::ProductOptionValue, ['option' => $optionCode]);
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
