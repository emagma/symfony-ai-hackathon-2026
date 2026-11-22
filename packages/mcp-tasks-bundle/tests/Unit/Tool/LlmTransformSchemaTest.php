<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Tool;

use Emagma\McpTasksBundle\Tool\LlmTransform;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmTransform::class)]
final class LlmTransformSchemaTest extends TestCase
{
    public function testTheOutputSchemaIsAdvertisedAsAnObjectOfTypes(): void
    {
        $schema = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new \ReflectionMethod(LlmTransform::class, '__invoke'));

        // The description asks for {"field": "type"}: a client, or a script's call(), sends a JSON object.
        self::assertSame('object', $schema['properties']['outputSchema']['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['outputSchema']['additionalProperties']);
    }
}
