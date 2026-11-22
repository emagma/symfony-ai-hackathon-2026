<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * The marks of the public properties of an output DTO, read once per class.
 */
final class FieldRegistry
{
    /** @var array<class-string, array<string, list<Field>>> */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * @param class-string $class
     *
     * @return array<string, list<Field>>
     */
    public static function of(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $marks = array_map(
                static fn (\ReflectionAttribute $attribute): Field => $attribute->newInstance()->field(),
                $property->getAttributes(FieldMark::class, \ReflectionAttribute::IS_INSTANCEOF),
            );
            if ([] !== $marks) {
                $fields[$property->getName()] = $marks;
            }
        }

        return self::$cache[$class] = $fields;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    public static function with(string $class, Field $field): array
    {
        return array_keys(array_filter(
            self::of($class),
            static fn (array $marks): bool => \in_array($field, $marks, true),
        ));
    }
}
