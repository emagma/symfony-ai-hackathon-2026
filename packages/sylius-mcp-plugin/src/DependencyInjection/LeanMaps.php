<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\DependencyInjection;

/**
 * Merge rules of the two lean maps, shared by the extension (raw keys, core
 * first) and ResolveLeanMapsPass (once the `%parameter%` keys are resolved, so
 * that `%sylius.model.product.class%` and the literal class it names merge
 * instead of one silently replacing the other).
 *
 * @internal
 */
final class LeanMaps
{
    /**
     * Same class twice: the later group wins, at the position of the first
     * (the order is the `is_a()` match order).
     *
     * @param array<string, string> ...$maps
     *
     * @return array<string, string>
     */
    public static function mergeGroups(array ...$maps): array
    {
        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $resourceClass => $group) {
                $merged[$resourceClass] = $group;
            }
        }

        return $merged;
    }

    /**
     * Same class and uriTemplate twice: the filter ids are appended, without
     * duplicates, in order of appearance.
     *
     * @param array<string, array<string, list<string>>> ...$maps
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function mergeFilters(array ...$maps): array
    {
        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $resourceClass => $byUriTemplate) {
                foreach ($byUriTemplate as $uriTemplate => $filterIds) {
                    $merged[$resourceClass][$uriTemplate] = array_values(array_unique([
                        ...$merged[$resourceClass][$uriTemplate] ?? [],
                        ...$filterIds,
                    ]));
                }
            }
        }

        return $merged;
    }
}
