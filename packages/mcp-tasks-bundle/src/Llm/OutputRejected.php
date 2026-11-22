<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Llm;

/**
 * What a model answered does not fit the schema the script declared.
 */
final class OutputRejected extends \RuntimeException
{
}
