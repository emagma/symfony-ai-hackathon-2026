<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Reading;

/**
 * The scripts of a task could not be read in plain language: the task is not created.
 */
final class ReadingFailed extends \RuntimeException
{
}
