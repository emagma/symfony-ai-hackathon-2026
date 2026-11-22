<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * Marks personal data: redacted by key name before anything is stored.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Personal implements FieldMark
{
    public function field(): Field
    {
        return Field::Personal;
    }
}
