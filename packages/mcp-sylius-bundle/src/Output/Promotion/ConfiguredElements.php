<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\Promotion;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Deliberately NOT a DTO: the update tools compare this with the normalised arguments using `===`,
 * and a list of objects is never `===` a list of arrays (bogus diff, pointless PUT).
 *
 * @internal
 */
final class ConfiguredElements
{
    private function __construct()
    {
    }

    /**
     * @return list<array{type: string|null, configuration: array<string, mixed>}>
     */
    public static function fromPayload(mixed $entries): array
    {
        if (!\is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $out[] = [
                'type' => AdminPayload::string($entry['type'] ?? null),
                'configuration' => \is_array($entry['configuration'] ?? null) ? $entry['configuration'] : [],
            ];
        }

        return $out;
    }
}
