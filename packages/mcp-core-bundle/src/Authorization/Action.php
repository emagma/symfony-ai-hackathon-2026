<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * The action of a permission: `write` and `delete` each imply `read`, and are independent of each other.
 */
enum Action: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';

    /**
     * Implied by no other action, nor by the `all` wildcard of another action.
     */
    case Reveal = 'reveal';
}
