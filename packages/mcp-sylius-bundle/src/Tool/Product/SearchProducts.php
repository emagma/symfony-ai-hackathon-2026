<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Product;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Lists the products, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_products',
    description: 'Search products (code + translated name). Basic filters: name (partial, translated), code'
        . ' (partial), taxon (exact code). Advanced, server-side: attributeEq {code: value} (exact'
        . ' attribute value, e.g. {"t_shirt_brand": "Modern Wear"}); attributePresent [code, ...] (any'
        . ' value for these attributes); attributeRange {code: {gte, lte}} (numeric or date attributes;'
        . ' ignored on text/json/boolean ones); channel (code; products available in that channel);'
        . ' priceRange {channel, gte, lte} (per-channel price in minor units of the channel currency;'
        . ' channel is REQUIRED, without it the filter is a no-op to avoid cross-currency matches). On a'
        . ' large catalog narrow with attribute or price filters rather than paging. Detail (descriptions, SEO'
        . ' meta, images, variants): get_products.',
)]
#[Permission(resource: 'product', action: Action::Read)]
final readonly class SearchProducts
{
    use LeanLocaleHeaderTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param array<string, scalar>|null $attributeEq
     * @param list<string>|null $attributePresent
     * @param array<string, array{gte?: int|float|string, lte?: int|float|string}>|null $attributeRange
     * @param array{channel?: string, gte?: int|float|string, lte?: int|float|string}|null $priceRange
     *
     * @return array{
     *     items: list<array{code: string, name: string|null}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    // The SchemaGenerator sees `?array` and would emit a JSON list for the attributeEq / attributeRange maps.
    // On the method: a parameter-level #[Schema] takes one type only, and `null` must stay valid.
    #[Schema(properties: [
        'attributeEq' => [
            'type' => ['object', 'null'],
            'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
        ],
        'attributeRange' => [
            'type' => ['object', 'null'],
            'additionalProperties' => [
                'type' => 'object',
                'properties' => [
                    'gte' => ['type' => ['number', 'string']],
                    'lte' => ['type' => ['number', 'string']],
                ],
                'additionalProperties' => false,
            ],
        ],
    ])]
    public function __invoke(
        ?string $name = null,
        ?string $code = null,
        ?string $taxon = null,
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
        ?array $attributeEq = null,
        ?array $attributePresent = null,
        ?array $attributeRange = null,
        ?string $channel = null,
        ?array $priceRange = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        $query = array_filter([
            'translations.name' => $name,
            'code' => $code,
            'productTaxons.taxon.code' => $taxon,
            'page' => $pager->page,
            'itemsPerPage' => $pager->itemsPerPage,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        if (null !== $attributeEq && [] !== $attributeEq) {
            $query['attribute'] = $attributeEq;
        }
        if (null !== $attributePresent && [] !== $attributePresent) {
            $query['attribute_present'] = array_values($attributePresent);
        }
        if (null !== $attributeRange && [] !== $attributeRange) {
            $this->validateAttributeRange($attributeRange);
            $query['attribute_range'] = $attributeRange;
        }
        if (null !== $channel && '' !== $channel) {
            $query['channel'] = $this->adapter->iri(Resource::Channel, $channel);
        }
        if (null !== $priceRange && [] !== $priceRange) {
            $this->validatePriceRange($priceRange);
            $query['price'] = $priceRange;
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::Product), $query, self::leanLocaleHeaders($locale));
        $members = AdminPayload::members($payload);
        $total = AdminPayload::totalItems($payload, \count($members));

        $items = [];
        foreach ($members as $member) {
            $items[] = [
                'code' => AdminPayload::text($member['code']),
                'name' => $this->resolveName($member['translations'] ?? [], $locale),
            ];
        }

        return $pager->envelope($items, $total);
    }

    /**
     * @param array<string, mixed> $range
     */
    private function validateAttributeRange(array $range): void
    {
        foreach ($range as $code => $bounds) {
            if (!\is_string($code) || '' === $code) {
                throw new \InvalidArgumentException('attributeRange keys must be non-empty attribute codes.');
            }
            if (!\is_array($bounds)) {
                throw new \InvalidArgumentException(sprintf('attributeRange["%s"] must be an array with gte/lte keys.', $code));
            }
            foreach ($bounds as $key => $value) {
                if (!\in_array($key, ['gte', 'lte'], true)) {
                    throw new \InvalidArgumentException(sprintf('attributeRange["%s"] only accepts "gte" and "lte" keys, got "%s".', $code, $key));
                }
                if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
                    throw new \InvalidArgumentException(sprintf('attributeRange["%s"]["%s"] must be a number or date string.', $code, $key));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $range
     */
    private function validatePriceRange(array $range): void
    {
        foreach ($range as $key => $value) {
            if (!\in_array($key, ['channel', 'gte', 'lte'], true)) {
                throw new \InvalidArgumentException(sprintf('priceRange only accepts "channel", "gte", "lte" keys, got "%s".', $key));
            }
        }
        if (!isset($range['channel']) || !\is_string($range['channel']) || '' === $range['channel']) {
            throw new \InvalidArgumentException('priceRange requires a non-empty "channel" code (e.g. "FASHION_WEB") to avoid cross-currency false positives.');
        }
        if (!isset($range['gte']) && !isset($range['lte'])) {
            throw new \InvalidArgumentException('priceRange requires at least one of "gte" or "lte".');
        }
        foreach (['gte', 'lte'] as $bound) {
            if (isset($range[$bound]) && !is_numeric($range[$bound])) {
                throw new \InvalidArgumentException(sprintf('priceRange["%s"] must be a number (minor units of the channel currency).', $bound));
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     */
    private function resolveName(array $translations, string $locale): ?string
    {
        if (isset($translations[$locale]['name']) && \is_string($translations[$locale]['name'])) {
            return $translations[$locale]['name'];
        }

        foreach ($translations as $translation) {
            if (isset($translation['name']) && \is_string($translation['name'])) {
                return $translation['name'];
            }
        }

        return null;
    }
}
