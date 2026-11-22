<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

/**
 * The tool was not called, or did not answer: unknown or forbidden tool, arguments outside its schema, exception.
 */
final class LocalToolCallFailed extends \RuntimeException
{
}
