<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolAnnotationsDeriver;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolAnnotationsDeriver::class)]
final class ToolAnnotationsDeriverTest extends TestCase
{
    public function testAReadToolIsReadOnlyAndIdempotent(): void
    {
        $tool = ToolAnnotationsDeriver::derive(
            $this->tool('list_products'),
            [new Permission('product', Action::Read, 'sylius')],
        );

        self::assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            $this->serialized($tool),
        );
    }

    public function testAWriteToolKeepsTheSpecDefaultsForDestructiveAndIdempotent(): void
    {
        $tool = ToolAnnotationsDeriver::derive(
            $this->tool('update_product'),
            [new Permission('product', Action::Write, 'sylius')],
        );

        self::assertSame(
            ['readOnlyHint' => false, 'openWorldHint' => false],
            $this->serialized($tool),
        );
    }

    public function testADeleteToolIsDestructive(): void
    {
        $tool = ToolAnnotationsDeriver::derive(
            $this->tool('delete_product'),
            [new Permission('product', Action::Delete, 'sylius')],
        );

        self::assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
            $this->serialized($tool),
        );
    }

    public function testAMixedToolIsNotReadOnlyWhenAnyPermissionWrites(): void
    {
        $tool = ToolAnnotationsDeriver::derive(
            $this->tool('update_product_taxons'),
            [new Permission('product', Action::Write, 'sylius'), new Permission('taxon', Action::Read, 'sylius')],
        );

        self::assertFalse($tool->annotations?->readOnlyHint);
    }

    public function testAToolThatChoseItsAnnotationsIsLeftAlone(): void
    {
        $chosen = new Tool('refund_payment', null, ['type' => 'object'], null, new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
        ));

        self::assertSame($chosen, ToolAnnotationsDeriver::derive($chosen, [new Permission('payment', Action::Write, 'sylius')]));
    }

    public function testAnUndeclaredToolIsLeftAlone(): void
    {
        $tool = $this->tool('undeclared_tool');

        self::assertSame($tool, ToolAnnotationsDeriver::derive($tool, []));
    }

    public function testEverythingElseOnTheToolIsPreserved(): void
    {
        $tool = ToolAnnotationsDeriver::derive(
            new Tool(
                'get_product',
                'Get product',
                ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
                'Get one product.',
                null,
                null,
                ['x' => 1],
                ['type' => 'object'],
            ),
            [new Permission('product', Action::Read, 'sylius')],
        );

        self::assertSame('get_product', $tool->name);
        self::assertSame('Get product', $tool->title);
        self::assertSame(['type' => 'object', 'properties' => ['code' => ['type' => 'string']]], $tool->inputSchema);
        self::assertSame('Get one product.', $tool->description);
        self::assertSame(['x' => 1], $tool->meta);
        self::assertSame(['type' => 'object'], $tool->outputSchema);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object'], null, null);
    }

    /**
     * @return array<string, bool|string>
     */
    private function serialized(Tool $tool): array
    {
        self::assertNotNull($tool->annotations);

        return $tool->annotations->jsonSerialize();
    }
}
