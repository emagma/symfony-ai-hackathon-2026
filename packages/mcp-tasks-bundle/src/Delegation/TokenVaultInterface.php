<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Delegation;

/**
 * Where the offline token of each user is kept, one per user, keyed by their stable identifier.
 */
interface TokenVaultInterface
{
    public function store(string $owner, #[\SensitiveParameter] string $token): void;

    public function fetch(string $owner): ?string;

    public function forget(string $owner): void;
}
