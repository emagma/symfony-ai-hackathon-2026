<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * One interface so the registry reads every mark in a single reflection call.
 */
interface FieldMark
{
    public function field(): Field;
}
