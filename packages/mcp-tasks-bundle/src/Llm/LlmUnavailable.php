<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Llm;

/**
 * The model of the server's own provider could not be reached or did not answer.
 */
final class LlmUnavailable extends \RuntimeException
{
}
