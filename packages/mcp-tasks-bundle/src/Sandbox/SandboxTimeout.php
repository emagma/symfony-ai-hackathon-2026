<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

/**
 * A sandbox session lasted longer than it was allowed.
 */
final class SandboxTimeout extends \RuntimeException
{
}
