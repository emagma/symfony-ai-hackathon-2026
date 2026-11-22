<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Repeatable with AND semantics: the caller must hold a grant for every declared permission.
 * A tool without it is never authorizable under real grants (fail-closed).
 *
 * A tool declares the resource and the action; the domain is the business solution its bundle covers
 * (`emagma_mcp_core.permission_domains`), given by {@see ToolPermissionResolver}. Without a domain a permission
 * matches no grant.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Permission
{
    public function __construct(
        public string $resource,
        public Action $action,
        public ?string $domain = null,
    ) {
    }

    public function in(string $domain): self
    {
        return new self($this->resource, $this->action, $domain);
    }

    /**
     * `<domain>:<resource>:<action>`, the grant without its `mcp:` prefix; `?` stands for a missing domain.
     */
    public function name(): string
    {
        return \sprintf('%s:%s:%s', $this->domain ?? '?', $this->resource, $this->action->value);
    }
}
