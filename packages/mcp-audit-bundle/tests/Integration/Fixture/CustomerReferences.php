<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Integration\Fixture;

use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;

/** A backend whose payloads point at a customer with an IRI ending in `/customers/<id>`. */
final class CustomerReferences implements SubjectReferencesInterface
{
    public function suffixes(string $type, string $id): array
    {
        return 'customer' === $type ? ['/customers/' . $id] : [];
    }
}
