<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * A list tool's opt-in to the plugin's lean representation, parsed by its `LeanRequestDetector`.
 * Without it the lean-gated plugin filters do not act: the shop answers the whole collection.
 *
 * @internal
 */
trait LeanLocaleHeaderTrait
{
    /**
     * @return array<string, string>
     */
    private static function leanHeaders(): array
    {
        return ['Prefer' => 'respond-for=lean'];
    }

    /**
     * @return array<string, string>
     */
    private static function leanLocaleHeaders(string $locale): array
    {
        // RFC 7240 §2: a preference may carry parameters after a semicolon.
        return ['Prefer' => 'respond-for=lean; locale=' . $locale];
    }
}
