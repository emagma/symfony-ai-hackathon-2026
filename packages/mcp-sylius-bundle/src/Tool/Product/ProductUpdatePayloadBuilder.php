<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Product;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final class ProductUpdatePayloadBuilder
{
    public function __construct(
        private readonly SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param array<string, mixed> $current
     * @param array{
     *     name: ?string, slug: ?string, description: ?string, shortDescription: ?string,
     *     metaKeywords: ?string, metaDescription: ?string,
     *     enabled: ?bool, mainTaxonCode: ?string,
     *     channelCodes: list<string>|null, optionCodes: list<string>|null,
     *     attributes: list<array{code?: mixed, locale?: mixed, value?: mixed}>|null
     * } $requested
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function computeDiff(array $current, string $locale, array $requested): array
    {
        $diff = [];
        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $currentTranslation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];

        foreach (['name', 'slug', 'description', 'shortDescription', 'metaKeywords', 'metaDescription'] as $field) {
            $requestedValue = $requested[$field];
            if (null === $requestedValue) {
                continue;
            }
            $before = $currentTranslation[$field] ?? null;
            if ($before === $requestedValue) {
                continue;
            }
            $diff[$locale . '.' . $field] = ['before' => $before, 'after' => $requestedValue];
        }

        if (null !== $requested['enabled'] && (bool) ($current['enabled'] ?? false) !== $requested['enabled']) {
            $diff['enabled'] = ['before' => (bool) ($current['enabled'] ?? false), 'after' => $requested['enabled']];
        }

        if (null !== $requested['mainTaxonCode']) {
            $beforeCode = AdminPayload::code($current['mainTaxon'] ?? null);
            $afterCode = '' === $requested['mainTaxonCode'] ? null : $requested['mainTaxonCode'];
            if ($beforeCode !== $afterCode) {
                $diff['mainTaxonCode'] = ['before' => $beforeCode, 'after' => $afterCode];
            }
        }

        if (null !== $requested['channelCodes']) {
            $before = AdminPayload::codes($current['channels'] ?? []);
            sort($before);
            $after = $requested['channelCodes'];
            sort($after);
            if ($before !== $after) {
                $diff['channelCodes'] = ['before' => $before, 'after' => $after];
            }
        }

        if (null !== $requested['optionCodes']) {
            $before = AdminPayload::codes($current['options'] ?? []);
            sort($before);
            $after = $requested['optionCodes'];
            sort($after);
            if ($before !== $after) {
                $diff['optionCodes'] = ['before' => $before, 'after' => $after];
            }
        }

        if (null !== $requested['attributes']) {
            $beforeAttrs = self::normaliseAttributes($this->extractCurrentAttributes($current));
            $afterAttrs = self::normaliseAttributes(self::shapeRequestedAttributes($requested['attributes']));
            if ($beforeAttrs !== $afterAttrs) {
                $diff['attributes'] = ['before' => $beforeAttrs, 'after' => $afterAttrs];
            }
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $current
     * @param array{
     *     name: ?string, slug: ?string, description: ?string, shortDescription: ?string,
     *     metaKeywords: ?string, metaDescription: ?string,
     *     enabled: ?bool, mainTaxonCode: ?string,
     *     channelCodes: list<string>|null, optionCodes: list<string>|null,
     *     attributes: list<array{code?: mixed, locale?: mixed, value?: mixed}>|null
     * } $requested
     * @param array{mainTaxonIri?: string|null, channelIris?: list<string>, optionIris?: list<string>, attributeIris?: array<string, string>} $references
     *
     * @return array<string, mixed>
     */
    public function buildPutBody(array $current, string $locale, array $requested, array $references): array
    {
        $body = $current;
        if (!\is_array($body['translations'] ?? null)) {
            $body['translations'] = [];
        }
        if (!\is_array($body['translations'][$locale] ?? null)) {
            $body['translations'][$locale] = [];
        }
        foreach (['name', 'slug', 'description', 'shortDescription', 'metaKeywords', 'metaDescription'] as $field) {
            $value = $requested[$field];
            if (null !== $value) {
                $body['translations'][$locale][$field] = $value;
            }
        }

        if (null !== $requested['enabled']) {
            $body['enabled'] = $requested['enabled'];
        }

        if (null !== $requested['mainTaxonCode']) {
            $body['mainTaxon'] = '' === $requested['mainTaxonCode']
                ? null
                : ($references['mainTaxonIri'] ?? null);
        }

        if (null !== $requested['channelCodes']) {
            $body['channels'] = $references['channelIris'] ?? [];
        }

        if (null !== $requested['optionCodes']) {
            $body['options'] = $references['optionIris'] ?? [];
        }

        if (null !== $requested['attributes']) {
            $attributeIris = $references['attributeIris'] ?? [];
            $body['attributes'] = [];
            foreach ($requested['attributes'] as $item) {
                $code = \is_string($item['code'] ?? null) ? $item['code'] : '';
                $localeCode = \is_string($item['locale'] ?? null) ? $item['locale'] : '';
                $body['attributes'][] = [
                    // Fallback when the batch lookup failed: a wrong IRI is a silent 422, so the adapter builds it.
                    'attribute' => $attributeIris[$code] ?? $this->adapter->iri(Resource::ProductAttribute, $code),
                    'localeCode' => $localeCode,
                    'value' => $item['value'] ?? null,
                ];
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return list<array{code: string, locale: string, value: mixed}>
     */
    private function extractCurrentAttributes(array $current): array
    {
        $out = [];
        $items = \is_array($current['attributes'] ?? null) ? $current['attributes'] : [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = AdminPayload::code($item['attribute'] ?? null);
            $locale = $item['localeCode'] ?? null;
            if (null === $code || !\is_string($locale)) {
                continue;
            }
            $out[] = ['code' => $code, 'locale' => $locale, 'value' => $item['value'] ?? null];
        }

        return $out;
    }

    /**
     * @param list<array{code?: mixed, locale?: mixed, value?: mixed}> $requested
     *
     * @return list<array{code: string, locale: string, value: mixed}>
     */
    private static function shapeRequestedAttributes(array $requested): array
    {
        $out = [];
        foreach ($requested as $item) {
            $out[] = [
                'code' => \is_string($item['code'] ?? null) ? $item['code'] : '',
                'locale' => \is_string($item['locale'] ?? null) ? $item['locale'] : '',
                'value' => $item['value'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{code: string, locale: string, value: mixed}> $items
     *
     * @return list<array{code: string, locale: string, value: mixed}>
     */
    private static function normaliseAttributes(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['code'], $a['locale']] <=> [$b['code'], $b['locale']]);

        return array_values($items);
    }
}
