<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;

/**
 * A payload points at a person by IRI, embedded or not: the erasure matches the IRI wherever it appears.
 *
 * @internal
 */
final class SyliusSubjectReferences implements SubjectReferencesInterface
{
    public function suffixes(string $type, string $id): array
    {
        return match ($type) {
            'customer' => ['/customers/' . $id],
            default => [],
        };
    }
}
