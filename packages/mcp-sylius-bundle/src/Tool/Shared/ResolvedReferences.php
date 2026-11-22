<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * @internal
 */
final readonly class ResolvedReferences
{
    /**
     * @param array<string, array<string, string>> $iris resource path => (code => IRI); empty when something is missing
     */
    public function __construct(
        public array $iris,
        public ?MissingReference $missing,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public function irisOf(string $resource, array $codes): array
    {
        $byCode = $this->iris[$resource] ?? [];

        return array_map(static fn (string $code): string => $byCode[$code], $codes);
    }

    public function iriOf(string $resource, string $code): string
    {
        return $this->iris[$resource][$code];
    }
}
