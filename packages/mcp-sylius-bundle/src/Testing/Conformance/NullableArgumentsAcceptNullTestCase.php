<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpSyliusBundle\ToolSources;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * OpenAI models send every property and null the unused ones. A #[Schema] on a nullable parameter replaces
 * the inferred type: `type: 'array'` or an `enum` without null turns "not given" into an invalid call.
 */
abstract class NullableArgumentsAcceptNullTestCase extends TestCase
{
    abstract protected static function tools(): ToolSources;

    protected static function minimumChecked(): int
    {
        return 1;
    }

    public function testEveryNullableArgumentOfEveryToolAcceptsAnExplicitNull(): void
    {
        $generator = new SchemaGenerator(new DocBlockParser());
        $validator = new SchemaValidator();
        $refused = [];
        $checked = 0;

        foreach (static::tools()->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(McpTool::class);
            if ([] === $attributes || !$reflection->hasMethod('__invoke')) {
                continue;
            }
            $method = $reflection->getMethod('__invoke');
            $schema = $generator->generate($method);
            $name = (string) $attributes[0]->newInstance()->name;

            foreach ($method->getParameters() as $parameter) {
                $properties = $schema['properties'] ?? null;
                $property = \is_array($properties) ? ($properties[$parameter->getName()] ?? null) : null;
                if (!\is_array($property) || !$parameter->allowsNull()) {
                    continue;
                }
                ++$checked;
                $errors = $validator->validateAgainstJsonSchema([$parameter->getName() => null], ['type' => 'object', 'properties' => [$parameter->getName() => $property]]);
                if ([] !== $errors) {
                    $refused[] = \sprintf('%s.%s: %s', $name, $parameter->getName(), $errors[0]['message']);
                }
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $checked, 'tool discovery looks broken');
        self::assertSame([], $refused, "These nullable arguments refuse an explicit null (#[Schema] needs type: [..., 'null'] or null in its enum):\n" . implode("\n", $refused));
    }
}
