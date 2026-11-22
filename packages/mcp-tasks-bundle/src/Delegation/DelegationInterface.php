<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Delegation;

/**
 * How the worker, which has no session, acts with the rights of the user who launched a task: rights read
 * when it acts, never a copy taken at launch.
 */
interface DelegationInterface
{
    public function isAuthorized(string $owner): bool;

    /**
     * Where the owner consents, once, to the server acting for them while they are away.
     */
    public function authorizationUrl(string $owner): string;

    /**
     * From now on the tools are called as this user, with their rights of this moment.
     *
     * @throws DelegationRevoked
     */
    public function actAs(string $owner): void;

    /**
     * Called before each tool call of a task: reads the owner's rights again when what is in hand got old.
     *
     * @throws DelegationRevoked
     */
    public function refresh(): void;

    /**
     * The worker is nobody again.
     */
    public function release(): void;

    public function revoke(string $owner): void;
}
