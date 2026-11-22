<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Journal;

/**
 * Extension point: how a backend's payloads reference a subject, so an erasure reaches the entries written on something else.
 */
interface SubjectReferencesInterface
{
    /**
     * The endings of the string values that reference the subject, e.g. `/customers/12` for an IRI.
     *
     * @return list<string>
     */
    public function suffixes(string $type, string $id): array;
}
