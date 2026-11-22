<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Delegation\TokenVaultInterface;

/**
 * A token vault in memory, for the suites of the bundles that create tasks.
 *
 * @internal
 */
final class InMemoryTokenVault implements TokenVaultInterface
{
    /** @var array<string, string> */
    public array $tokens = [];

    public function store(string $owner, #[\SensitiveParameter] string $token): void
    {
        $this->tokens[$owner] = $token;
    }

    public function fetch(string $owner): ?string
    {
        return $this->tokens[$owner] ?? null;
    }

    public function forget(string $owner): void
    {
        unset($this->tokens[$owner]);
    }
}
