<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * Marks personal data the model does not need to act (a birthday, a gender): left out under `pseudonymise`.
 * Explicit on purpose: deducing it from "personal without untrusted" would also drop an administrator's name.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Surplus implements FieldMark
{
    public function field(): Field
    {
        return Field::Surplus;
    }
}
