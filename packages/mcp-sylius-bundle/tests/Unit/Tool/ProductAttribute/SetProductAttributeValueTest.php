<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAttribute;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAttribute\SetProductAttributeValue;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SetProductAttributeValue::class)]
final class SetProductAttributeValueTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string PRODUCT_PATH = 'products/MUG';

    private const string MATERIAL_IRI = '/api/v2/admin/product-attributes/material';

    private const string COLOUR_IRI = '/api/v2/admin/product-attributes/colour';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SetProductAttributeValue::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('set_product_attribute_value', $attributes[0]->newInstance()->name);
    }

    public function testValueIsAdvertisedWithoutATypeConstraint(): void
    {
        $schema = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new \ReflectionMethod(SetProductAttributeValue::class, '__invoke'));

        // A PHP union or a `@param` line would make the generator emit `type: array`.
        self::assertArrayNotHasKey('type', $schema['properties']['value']);
        self::assertArrayHasKey('description', $schema['properties']['value']);
        self::assertContains('value', $schema['required']);
    }

    public function testItReturnsNotFoundWhenProductAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'NOPE',
            attributeCode: 'material',
            value: 'Ceramic',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItRejectsANullValue(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: null,
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('invalid_value', $result['reason']);
    }

    public function testItRejectsANonScalarNonArrayValue(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: new \stdClass(),
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('invalid_value', $result['reason']);
    }

    public function testItReturnsNoChangeWhenTheValueIsIdentical(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Ceramic',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('no_change', $result['reason']);
    }

    public function testItReplacesAnExistingValueInPlaceWithoutLookingUpTheAttribute(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with(self::PRODUCT_PATH, [], [])
            ->willReturn($this->productPayload());

        $client->expects(self::once())
            ->method('put')
            ->with(self::PRODUCT_PATH, self::callback(static function (array $body): bool {
                self::assertCount(2, $body['attributes']);
                self::assertSame('Porcelain', $body['attributes'][0]['value']);
                // The @id marker must survive so Doctrine updates the row.
                self::assertSame('/api/v2/admin/product-attribute-values/1', $body['attributes'][0]['@id']);
                self::assertSame(self::COLOUR_IRI, $body['attributes'][1]['attribute']);
                self::assertSame('Blue', $body['attributes'][1]['value']);

                return true;
            }), [])
            ->willReturn($this->productPayload());

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Porcelain',
            confirm: true,
        );

        self::assertTrue($result['updated']);
        self::assertSame(
            ['attribute.material.en_US' => ['before' => 'Ceramic', 'after' => 'Porcelain']],
            $result['diff'],
        );
    }

    public function testItAppendsANewValueAfterResolvingTheAttributeIri(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match ((string) $path) {
            self::PRODUCT_PATH => $this->productPayload(),
            'product-attributes/finish' => ['@id' => '/api/v2/admin/product-attributes/finish', 'code' => 'finish'],
            default => throw new \LogicException('unexpected path ' . $path),
        });

        $client->expects(self::once())
            ->method('put')
            ->with(self::PRODUCT_PATH, self::callback(static function (array $body): bool {
                self::assertCount(3, $body['attributes']);
                self::assertSame([
                    'attribute' => '/api/v2/admin/product-attributes/finish',
                    'localeCode' => self::LOCALE,
                    'value' => 'Matte',
                ], $body['attributes'][2]);

                return true;
            }), [])
            ->willReturn($this->productPayload());

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'finish',
            value: 'Matte',
            confirm: true,
        );

        self::assertTrue($result['updated']);
        self::assertNull($result['before']);
    }

    public function testItReturnsProductAttributeNotFoundWhenTheAttributeDoesNotExist(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match ((string) $path) {
            self::PRODUCT_PATH => $this->productPayload(),
            default => throw $this->clientException(404),
        });
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'ghost',
            value: 'x',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('product_attribute_not_found', $result['reason']);
    }

    public function testItAcceptsAListValueForSelectAttributes(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match ((string) $path) {
            self::PRODUCT_PATH => $this->productPayload(),
            default => ['@id' => '/api/v2/admin/product-attributes/tags', 'code' => 'tags'],
        });
        $client->expects(self::once())
            ->method('put')
            ->with(self::PRODUCT_PATH, self::callback(static function (array $body): bool {
                self::assertSame(['gift', 'ceramic'], $body['attributes'][2]['value']);

                return true;
            }), [])
            ->willReturn($this->productPayload());

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'tags',
            value: ['gift', 'ceramic'],
            confirm: true,
        );

        self::assertTrue($result['updated']);
    }

    public function testItTargetsOnlyTheRequestedLocale(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match ((string) $path) {
            self::PRODUCT_PATH => $this->productPayload(),
            default => ['@id' => self::MATERIAL_IRI, 'code' => 'material'],
        });
        $client->expects(self::once())
            ->method('put')
            ->with(self::PRODUCT_PATH, self::callback(static function (array $body): bool {
                self::assertSame('Ceramic', $body['attributes'][0]['value']);
                self::assertSame('fr_FR', $body['attributes'][2]['localeCode']);
                self::assertSame('Céramique', $body['attributes'][2]['value']);

                return true;
            }), [])
            ->willReturn($this->productPayload());

        $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Céramique',
            locale: 'fr_FR',
            confirm: true,
        );
    }

    public function testItRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->method('put')->willReturn($this->productPayload());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'set_product_attribute_value' === $e->getTool() &&
                'product' === $e->getTargetType() &&
                'MUG' === $e->getTargetCode(),
        ));

        $this->call(
            new SetProductAttributeValue($client, $audit, self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Porcelain',
            confirm: true,
        );
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'attributes[0].value', 'message' => 'This value is not valid.'],
        ]));

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Porcelain',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame(
            [['property' => 'attributes[0].value', 'message' => 'This value is not valid.']],
            $result['errors'],
        );
    }

    public function testItReturnsPreviewOnlyWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new SetProductAttributeValue($client, $this->audit(), self::LOCALE),
            code: 'MUG',
            attributeCode: 'material',
            value: 'Porcelain',
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['updated']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::once())->method('put')->willReturn($this->productPayload());

        $tool = new SetProductAttributeValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'MUG',
                attributeCode: 'material',
                value: 'Porcelain',
                context: $context,
            ),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['updated']);
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->productPayload());
        $client->expects(self::never())->method('put');

        $tool = new SetProductAttributeValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'MUG',
                attributeCode: 'material',
                value: 'Porcelain',
                confirm: true,
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['updated']);
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
