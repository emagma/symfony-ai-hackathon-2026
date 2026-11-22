<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * Marks text written by a member of the public: named in `untrustedContent`, so that the model reads it as data.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Untrusted implements FieldMark
{
    public function field(): Field
    {
        return Field::Untrusted;
    }
}
