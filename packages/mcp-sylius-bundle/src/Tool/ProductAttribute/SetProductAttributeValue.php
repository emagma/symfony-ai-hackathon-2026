<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAttribute;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * ProductAttributeValue is read-only in the admin API and Product has no PATCH: the product PUT
 * replaces the whole `attributes` collection, so the other entries are round-tripped as the GET returned them.
 *
 * @internal
 */
#[McpTool(
    name: 'set_product_attribute_value',
    description: 'Set ONE attribute value on ONE product for ONE locale, leaving every other attribute value'
        . ' untouched. Prefer this over upsert_products(attributes=), which replaces the whole collection'
        . ' and wipes the values you omit. Arguments: code (the product), attributeCode, value (scalar, or'
        . ' a list of choice keys for select-typed attributes - check the type via'
        . ' get_product_attributes), locale. Creates the value when absent, replaces it when present.'
        . ' Returns {updated: true, code, attributeCode, locale, diff, before, after} on success.'
        . ' Tool-specific reasons: product_attribute_not_found.',
)]
#[Permission(resource: 'product', action: Action::Write)]
#[Permission(resource: 'product_attribute', action: Action::Read)]
final readonly class SetProductAttributeValue
{
    use WriteConfirmationTrait;
    use ProductAttributeValuesPayloadTrait;

    private const string TARGET_TYPE = 'product';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
    ) {
    }

    /**
     * `$value` deliberately carries neither a union type nor a `@param` line:
     * the SchemaGenerator collapses both to a single JSON type (it picks
     * `array`), which would tell the model that a text or checkbox attribute
     * expects a list. `mixed` with no docblock emits an unconstrained property
     * - the only faithful schema for a value whose type depends on the
     * attribute's storage type - and the shape is enforced below instead.
     * Same class of trap as the {key: value} object overrides in SearchProducts.
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        string $attributeCode,
        #[Schema(description: 'Scalar for text/textarea/integer/float/percent/checkbox/date attributes;'
            . ' list of choice keys for select-typed ones. Check the type via get_product_attributes.')]
        mixed $value,
        ?string $locale = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $locale ??= $this->defaultLocale;
        $productPath = Route::item(Resource::Product, $code);

        if (null === $value) {
            return [
                'ok' => false,
                'updated' => false,
                'reason' => 'invalid_value',
                'code' => $code,
                'attributeCode' => $attributeCode,
                'hint' => 'Use remove_product_attribute_value to clear a value.',
            ];
        }
        if (!\is_scalar($value) && !\is_array($value)) {
            return [
                'ok' => false,
                'updated' => false,
                'reason' => 'invalid_value',
                'code' => $code,
                'attributeCode' => $attributeCode,
                'hint' => 'value must be a scalar or a list of choice keys.',
            ];
        }

        try {
            $current = $this->client->getDecoded($productPath);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code]);
        }

        $entries = self::attributeEntries($current);
        $index = self::findAttributeEntry($entries, $attributeCode, $locale);

        $before = null === $index ? null : ($entries[$index]['value'] ?? null);
        if (null !== $index && $before === $value) {
            return [
                'ok' => false,
                'updated' => false,
                'reason' => 'no_change',
                'code' => $code,
                'attributeCode' => $attributeCode,
                'locale' => $locale,
            ];
        }

        if (null === $index) {
            $attributeIri = $this->lookupAttributeIri($attributeCode);
            if (null === $attributeIri) {
                return [
                    'ok' => false,
                    'updated' => false,
                    'reason' => 'product_attribute_not_found',
                    'code' => $code,
                    'attributeCode' => $attributeCode,
                ];
            }
        }

        $diff = [self::diffKey($attributeCode, $locale) => ['before' => $before, 'after' => $value]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm setting attribute "' . $attributeCode . '" on product "' . $code . '"',
            'Apply this product attribute value?',
            DiffPreview::targets('Set attribute on product "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return [
                'ok' => false,
                'updated' => false,
                'code' => $code,
                'attributeCode' => $attributeCode,
                'locale' => $locale,
                'diff' => $diff,
            ] + $refusal;
        }

        if (null === $index) {
            $entries[] = [
                'attribute' => $attributeIri,
                'localeCode' => $locale,
                'value' => $value,
            ];
        } else {
            // In place: the entry keeps its @id, so Doctrine updates the row instead of recreating it.
            $entries[$index]['value'] = $value;
        }

        $body = $current;
        $body['attributes'] = array_values($entries);

        try {
            $updated = $this->client->put($productPath, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code, 'attributeCode' => $attributeCode]);
        }

        $this->audit->record(
            tool: 'set_product_attribute_value',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'code' => $code,
            'attributeCode' => $attributeCode,
            'locale' => $locale,
            'diff' => $diff,
            'before' => $before,
            'after' => $value,
        ];
    }

    private function lookupAttributeIri(string $attributeCode): ?string
    {
        try {
            $attribute = $this->client->getDecoded(
                Route::item(Resource::ProductAttribute, $attributeCode),
            );
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return null;
            }

            throw $exception;
        }

        $iri = $attribute['@id'] ?? null;

        return \is_string($iri) && '' !== $iri ? $iri : null;
    }
}
