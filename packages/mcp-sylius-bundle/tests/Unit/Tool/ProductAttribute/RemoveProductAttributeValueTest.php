<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAttribute;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAttribute\RemoveProductAttributeValue;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RemoveProductAttributeValue::class)]
final class RemoveProductAttributeValueTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string PRODUCT_PATH = 'products/MUG';

    private const string MATERIAL_IRI = '/api/v2/admin/product-attributes/material';

    private const string COLOUR_IRI = '/api/v2/admin/product-attributes/colour';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(RemoveProductAttributeValue::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('remove_product_attribute_value', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsNotFoundWhenProductAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'NOPE',
            attributeCode: 'material',
            confirm: true,
        );

        self::assertFalse($result['removed']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItReturnsValueNotSetWhenTheAttributeHasNoValue(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'finish',
            confirm: true,
        );

        self::assertFalse($result['removed']);
        self::assertSame('value_not_set', $result['reason']);
    }

    public function testItReturnsValueNotSetForAnotherLocale(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            locale: 'fr_FR',
            confirm: true,
        );

        self::assertFalse($result['removed']);
        self::assertSame('value_not_set', $result['reason']);
    }

    public function testItDropsOnlyTheTargetedEntry(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            // No attribute lookup: the dropped entry carries its own IRI.
            ->with(self::PRODUCT_PATH, [], [])
            ->willReturn($this->productPayload());

        $client->expects(self::once())
            ->method('put')
            ->with(self::PRODUCT_PATH, self::callback(static function (array $body): bool {
                self::assertCount(1, $body['attributes']);
                self::assertSame(0, array_key_first($body['attributes']));
                self::assertSame(self::COLOUR_IRI, $body['attributes'][0]['attribute']);
                self::assertSame('Blue', $body['attributes'][0]['value']);

                return true;
            }), [])
            ->willReturn($this->productPayload());

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            confirm: true,
        );

        self::assertTrue($result['removed']);
        self::assertSame(
            ['attribute.material.en_US' => ['before' => 'Ceramic', 'after' => null]],
            $result['diff'],
        );
    }

    public function testItRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->method('put')->willReturn($this->productPayload());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'remove_product_attribute_value' === $e->getTool() &&
                'product' === $e->getTargetType() &&
                'MUG' === $e->getTargetCode(),
        ));

        $this->call(
            new RemoveProductAttributeValue($client, $audit, self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            confirm: true,
        );
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'attributes', 'message' => 'This attribute is required.'],
        ]));

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            confirm: true,
        );

        self::assertFalse($result['removed']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame(
            [['property' => 'attributes', 'message' => 'This attribute is required.']],
            $result['errors'],
        );
    }

    public function testItReturnsPreviewOnlyWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['removed']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::once())->method('put')->willReturn($this->productPayload());

        $tool = new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(code: 'MUG', attributeCode: 'material', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['removed']);
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $tool = new RemoveProductAttributeValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'MUG',
                attributeCode: 'material',
                confirm: true,
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['removed']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(): array
    {
        return [
            '@id' => '/api/v2/admin/products/MUG',
            'code' => 'MUG',
            'attributes' => [
                [
                    '@id' => '/api/v2/admin/product-attribute-values/1',
                    'attribute' => self::MATERIAL_IRI,
                    'localeCode' => self::LOCALE,
                    'value' => 'Ceramic',
                ],
                [
                    '@id' => '/api/v2/admin/product-attribute-values/2',
                    'attribute' => self::COLOUR_IRI,
                    'localeCode' => self::LOCALE,
                    'value' => 'Blue',
                ],
            ],
        ];
    }
}
