<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Testing;

use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Delegation\DelegationRevoked;

/**
 * A delegation that records who the worker acted as, for the suites of the bundles that create tasks.
 *
 * @internal
 */
final class FakeDelegation implements DelegationInterface
{
    public bool $authorized = true;

    public bool $revoked = false;

    /** @var list<string|null> who the worker acted as, null when it released */
    public array $acted = [];

    public function isAuthorized(string $owner): bool
    {
        return $this->authorized;
    }

    public function authorizationUrl(string $owner): string
    {
        return 'https://idp.example/authorize?for=' . $owner;
    }

    public function actAs(string $owner): void
    {
        if ($this->revoked) {
            throw new DelegationRevoked('The authorization was revoked.');
        }
        $this->acted[] = $owner;
    }

    public function refresh(): void
    {
        if ($this->revoked) {
            throw new DelegationRevoked('The authorization was revoked.');
        }
    }

    public function release(): void
    {
        $this->acted[] = null;
    }

    public function revoke(string $owner): void
    {
        $this->authorized = false;
        $this->revoked = true;
    }
}
