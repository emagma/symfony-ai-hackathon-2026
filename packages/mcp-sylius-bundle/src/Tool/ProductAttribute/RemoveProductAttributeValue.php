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
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Removes the value of one attribute of a product, in one locale.
 *
 * @internal
 */
#[McpTool(
    name: 'remove_product_attribute_value',
    description: 'Remove ONE attribute value from ONE product for ONE locale, leaving every other attribute value'
        . ' untouched. This clears the value entirely; use set_product_attribute_value to overwrite it'
        . ' instead. Arguments: code (the product), attributeCode, locale. Returns {removed: true, code,'
        . ' attributeCode, locale, diff} on success. Tool-specific reasons: value_not_set.',
)]
#[Permission(resource: 'product', action: Action::Write)]
final readonly class RemoveProductAttributeValue
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
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        string $attributeCode,
        ?string $locale = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $locale ??= $this->defaultLocale;
        $productPath = Route::item(Resource::Product, $code);

        try {
            $current = $this->client->getDecoded($productPath);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['removed' => false, 'code' => $code]);
        }

        $entries = self::attributeEntries($current);
        $index = self::findAttributeEntry($entries, $attributeCode, $locale);

        if (null === $index) {
            return [
                'ok' => false,
                'removed' => false,
                'reason' => 'value_not_set',
                'code' => $code,
                'attributeCode' => $attributeCode,
                'locale' => $locale,
            ];
        }

        $before = $entries[$index]['value'] ?? null;
        $diff = [self::diffKey($attributeCode, $locale) => ['before' => $before, 'after' => null]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm removing attribute "' . $attributeCode . '" from product "' . $code . '"',
            'Remove this product attribute value?',
            DiffPreview::targets('Remove attribute from product "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return [
                'ok' => false,
                'removed' => false,
                'code' => $code,
                'attributeCode' => $attributeCode,
                'locale' => $locale,
                'diff' => $diff,
            ] + $refusal;
        }

        unset($entries[$index]);

        $body = $current;
        $body['attributes'] = array_values($entries);

        try {
            $updated = $this->client->put($productPath, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['removed' => false, 'code' => $code, 'attributeCode' => $attributeCode]);
        }

        $this->audit->record(
            tool: 'remove_product_attribute_value',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'removed' => true,
            'code' => $code,
            'attributeCode' => $attributeCode,
            'locale' => $locale,
            'diff' => $diff,
        ];
    }
}
