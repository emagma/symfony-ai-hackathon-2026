<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Script;

/**
 * A script the server will not store: `reason` is what the tool answers, the message says where.
 */
final class ScriptRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
