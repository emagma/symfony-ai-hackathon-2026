<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * Marks a direct identifier of a person (email, phone, login): replaced by a stable token under `pseudonymise`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Identifier implements FieldMark
{
    public function field(): Field
    {
        return Field::Identifier;
    }
}
