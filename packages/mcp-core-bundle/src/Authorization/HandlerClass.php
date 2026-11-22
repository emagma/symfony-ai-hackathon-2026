<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * The class behind the handler of a tool reference, and what its `#[Deprecated]` says: what a tool gate reads.
 */
final class HandlerClass
{
    private function __construct()
    {
    }

    /**
     * @param \Closure|array{0: object|string, 1?: string}|string $handler
     *
     * @return class-string|null null for a closure, which carries no attribute
     */
    public static function of(\Closure|array|string $handler): ?string
    {
        if ($handler instanceof \Closure) {
            return null;
        }
        if (\is_array($handler)) {
            $target = $handler[0];
            if (\is_object($target)) {
                return $target::class;
            }

            return class_exists($target) ? $target : null;
        }

        return class_exists($handler) ? $handler : null;
    }

    /**
     * @param \Closure|array{0: object|string, 1?: string}|string $handler
     */
    public static function deprecatedFor(\Closure|array|string $handler): ?string
    {
        $class = self::of($handler);
        if (null === $class) {
            return null;
        }

        // Holds class names only, never caller state: safe in a long-lived worker.
        /** @var array<class-string, string|false> $cache */
        static $cache = [];
        if (!\array_key_exists($class, $cache)) {
            $attributes = (new \ReflectionClass($class))->getAttributes(Deprecated::class);
            $cache[$class] = [] === $attributes ? false : $attributes[0]->newInstance()->replacedBy;
        }

        return false === $cache[$class] ? null : $cache[$class];
    }
}
