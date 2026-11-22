<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

/**
 * The call was not made or did not answer a result: unknown or forbidden tool, arguments outside the
 * schema, tool error. Its message is what the script receives.
 */
final class ToolCallFailed extends \RuntimeException
{
}
