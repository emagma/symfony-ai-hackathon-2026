<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Privacy;

/**
 * How much of a person a tool result shows the model.
 */
enum PrivacyMode: string
{
    case Off = 'off';

    /**
     * Identifiers become stable tokens and surplus fields are left out; names and free text pass.
     */
    case Pseudonymise = 'pseudonymise';
}
