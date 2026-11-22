<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Journal;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;

/**
 * Marks a write tool whose change writing the old state back cannot undo: a state transition
 * (cancel, refund, ship, accept…), a file replaced, an email sent. A tool declaring a `delete`
 * permission is irreversible without it. The journal records it on the entry, and a revert of
 * that entry is refused before anything is read.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Irreversible
{
    /**
     * @param class-string $class
     */
    public static function declaredBy(string $class): bool
    {
        $reflection = new \ReflectionClass($class);
        if ([] !== $reflection->getAttributes(self::class)) {
            return true;
        }

        foreach ($reflection->getAttributes(Permission::class) as $attribute) {
            if (Action::Delete === $attribute->newInstance()->action) {
                return true;
            }
        }

        return false;
    }
}
