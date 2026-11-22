<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\EventListener;

use Mcp\Capability\RegistryInterface;
use Mcp\Event\RequestEvent;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Request\CallToolRequest;

/**
 * Works around mcp/sdk, which decodes `{}` and `[]` to the same empty array and rejects a nested empty object as a list.
 * An optional object-typed argument received empty is dropped before validation; a required one is never touched.
 *
 * @internal
 */
final class EmptyObjectArgumentListener
{
    public function __construct(
        private readonly RegistryInterface $registry,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof CallToolRequest || !self::containsAnEmptyArray($request->arguments)) {
            return;
        }

        try {
            $schema = $this->registry->getTool($request->name)->tool->inputSchema;
        } catch (ToolNotFoundException) {
            return;
        }

        // An empty `properties` is a \stdClass, which the SDK keeps so that it encodes as `{}`.
        $properties = $schema['properties'] ?? [];
        if (!\is_array($properties)) {
            return;
        }
        $required = $schema['required'] ?? [];

        $arguments = $request->arguments;
        foreach ($arguments as $name => $value) {
            $property = $properties[$name] ?? null;
            if (!\is_array($property)) {
                continue;
            }
            if ([] === $value && self::isObject($property) && !\in_array($name, $required, true)) {
                unset($arguments[$name]);

                continue;
            }
            // A batch tool's `items[]`: the same rule, one level down.
            $itemSchema = $property['items'] ?? null;
            if (\is_array($value) && \is_array($itemSchema) && \is_array($itemSchema['properties'] ?? null)) {
                $arguments[$name] = self::withoutEmptyObjects($value, $itemSchema['properties'], $itemSchema['required'] ?? []);
            }
        }

        if ($arguments !== $request->arguments) {
            $event->setRequest((new CallToolRequest($request->name, $arguments))->withId($request->getId())->withMeta($request->getMeta()));
        }
    }

    /**
     * @param array<mixed> $values
     */
    private static function containsAnEmptyArray(array $values): bool
    {
        foreach ($values as $value) {
            if ([] === $value) {
                return true;
            }
            if (\is_array($value)) {
                foreach ($value as $nested) {
                    if (\is_array($nested) && \in_array([], $nested, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $property
     */
    private static function isObject(array $property): bool
    {
        $type = $property['type'] ?? null;

        return 'object' === $type || (\is_array($type) && \in_array('object', $type, true));
    }

    /**
     * @param array<mixed> $items
     * @param array<mixed> $properties
     *
     * @return array<mixed>
     */
    private static function withoutEmptyObjects(array $items, array $properties, mixed $required): array
    {
        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }
            foreach ($item as $key => $value) {
                $property = $properties[$key] ?? null;
                if ([] === $value && \is_array($property) && self::isObject($property) && !(\is_array($required) && \in_array($key, $required, true))) {
                    unset($item[$key]);
                }
            }
            $items[$index] = $item;
        }

        return $items;
    }
}
