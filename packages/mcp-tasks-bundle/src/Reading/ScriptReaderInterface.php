<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Reading;

/**
 * Says in plain language what the scripts of a task do, for the human who validates it. It only sees the
 * code: neither the conversation nor what the model that wrote the scripts meant.
 */
interface ScriptReaderInterface
{
    /**
     * @param list<array{name: string, script: string}> $steps
     *
     * @throws ReadingFailed
     */
    public function read(string $objective, string $params, array $steps): string;
}
