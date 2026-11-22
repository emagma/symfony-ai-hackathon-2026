<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Delegation;

/**
 * The owner of a task no longer lets the server act for them: revoked, expired, account disabled.
 */
final class DelegationRevoked extends \RuntimeException
{
}
