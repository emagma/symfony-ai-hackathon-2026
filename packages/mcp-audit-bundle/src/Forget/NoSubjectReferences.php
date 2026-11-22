<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Forget;

use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;

/**
 * The default: an erasure reaches the entries written on the subject itself, and no other.
 *
 * @internal
 */
final class NoSubjectReferences implements SubjectReferencesInterface
{
    public function suffixes(string $type, string $id): array
    {
        return [];
    }
}
