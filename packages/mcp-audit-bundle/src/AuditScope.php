<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;

/**
 * Whose entries the caller reaches. `audit:<action>` opens the tools on the caller's own entries
 * (`actor` = the caller); `audit_all:<action>` widens them to every entry, an entry without actor
 * (CLI) included. Another's entry answers as a missing one.
 *
 * @internal
 */
final readonly class AuditScope
{
    /** The resource of the grants that widen the audit to every entry: `mcp:<domain>:audit_all:<action>`. */
    public const string ALL = 'audit_all';

    public function __construct(
        private GrantsProviderInterface $grants,
        private ActorProviderInterface $actors,
        private ToolPermissionResolver $permissions,
        private ToolClassMap $tools,
    ) {
    }

    /**
     * @return string|false|null null for every entry, the caller's actor for their own, false for none
     *                           (a caller without identity reaches no one's entries but through `audit_all`)
     */
    public function actor(Action $action): string|false|null
    {
        if ($this->grants->grants()->allows($this->all($action))) {
            return null;
        }

        return $this->actors->actor() ?? false;
    }

    public function reaches(AuditEntry $entry, Action $action): bool
    {
        $actor = $this->actor($action);

        return null === $actor || (false !== $actor && $actor === $entry->getActor());
    }

    /**
     * Whether the caller holds the grants of the tool that recorded an entry: a revert is a write of that tool.
     * A name the server no longer exposes is refused, but for an explicit allow-all.
     */
    public function mayCall(string $tool): bool
    {
        $grants = $this->grants->grants();
        $class = $this->tools->classOf($tool);
        if (null === $class) {
            return $grants->isAllowAll();
        }

        return $grants->allowsAll($this->permissions->permissionsOf($class));
    }

    private function all(Action $action): Permission
    {
        // The audit's domain (`core` by default), as for the tools' own #[Permission]: none matches no grant.
        return new Permission(self::ALL, $action, $this->permissions->domainOf(self::class));
    }
}
