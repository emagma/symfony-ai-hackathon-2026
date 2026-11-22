<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Reading\ScriptReaderInterface;

/**
 * A reading that only names the steps, for the suites of the bundles that create tasks.
 *
 * @internal
 */
final class FakeScriptReader implements ScriptReaderInterface
{
    public function read(string $objective, string $params, array $steps): string
    {
        return $objective . ': ' . implode(', ', array_column($steps, 'name'));
    }
}
