<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * A permission the tool checks itself to serve more than its #[Permission] gives: every entry instead of the caller's own.
 * Not required to call the tool: a caller without it is served the narrower scope. Listed by `mcp:permissions`, so that
 * the identity provider mints its role; the domain is the tool's, as for #[Permission].
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class WidenedBy
{
    public function __construct(
        public string $resource,
        public Action $action,
        public ?string $domain = null,
    ) {
    }

    public function permission(): Permission
    {
        return new Permission($this->resource, $this->action, $this->domain);
    }
}
