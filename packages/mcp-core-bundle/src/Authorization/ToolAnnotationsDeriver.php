<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * Derives the annotations of a tool from its #[Permission] attributes; a tool that sets its own is never overridden.
 * `destructiveHint` and `idempotentHint` stay null on a plain write: the defaults of the specification are the safe ones.
 *
 * @internal
 */
final class ToolAnnotationsDeriver
{
    /**
     * @param list<Permission> $required
     */
    public static function derive(Tool $tool, array $required): Tool
    {
        if (null !== $tool->annotations || [] === $required) {
            return $tool;
        }

        $actions = array_map(static fn (Permission $permission): Action => $permission->action, $required);
        $deletes = \in_array(Action::Delete, $actions, true);
        $readOnly = !$deletes && !\in_array(Action::Write, $actions, true);

        return new Tool(
            $tool->name,
            $tool->title,
            $tool->inputSchema,
            $tool->description,
            new ToolAnnotations(
                readOnlyHint: $readOnly,
                destructiveHint: $deletes ? true : null,
                idempotentHint: $readOnly ? true : null,
                openWorldHint: false,
            ),
            $tool->icons,
            $tool->meta,
            $tool->outputSchema,
            $tool->execution,
        );
    }
}
