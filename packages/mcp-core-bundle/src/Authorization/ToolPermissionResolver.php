<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

use Mcp\Capability\Registry\ToolReference;

/**
 * Reads the #[Permission] and #[WidenedBy] of a tool class and gives each permission the domain of its namespace.
 * The cache holds attribute data by class name, never caller state: safe in a long-lived worker.
 */
final class ToolPermissionResolver
{
    /** @var array<class-string, list<Permission>> */
    private array $cache = [];

    /** @var array<class-string, list<Permission>> */
    private array $wideningCache = [];

    /**
     * @param array<string, string> $domains namespace prefix => permission domain, each bundle giving its own
     */
    public function __construct(
        private readonly array $domains = [],
    ) {
    }

    /**
     * @return list<Permission>
     */
    public function resolve(ToolReference $reference): array
    {
        $class = HandlerClass::of($reference->handler);
        if (null === $class) {
            return [];
        }

        return $this->permissionsOf($class);
    }

    /**
     * The #[Permission]s of a tool class, each in its domain: what calling the tool requires.
     *
     * @param class-string $class
     *
     * @return list<Permission>
     */
    public function permissionsOf(string $class): array
    {
        return $this->cache[$class] ??= $this->fromAttributes($class, Permission::class);
    }

    /**
     * The #[WidenedBy] permissions of a tool class, each in its domain: what the tool checks itself to serve more.
     *
     * @param class-string $class
     *
     * @return list<Permission>
     */
    public function wideningOf(string $class): array
    {
        return $this->wideningCache[$class] ??= $this->fromAttributes($class, WidenedBy::class);
    }

    /**
     * The longest matching prefix wins; null leaves the permissions of the class without a grant that fits.
     */
    public function domainOf(string $class): ?string
    {
        $found = null;
        $length = -1;
        foreach ($this->domains as $prefix => $domain) {
            if (str_starts_with($class, $prefix) && \strlen($prefix) > $length) {
                $found = $domain;
                $length = \strlen($prefix);
            }
        }

        return $found;
    }

    /**
     * @param class-string                                  $class
     * @param class-string<Permission>|class-string<WidenedBy> $attributeClass
     *
     * @return list<Permission>
     */
    private function fromAttributes(string $class, string $attributeClass): array
    {
        $domain = $this->domainOf($class);
        $permissions = [];
        foreach ((new \ReflectionClass($class))->getAttributes($attributeClass) as $attribute) {
            $declared = $attribute->newInstance();
            $permission = $declared instanceof WidenedBy ? $declared->permission() : $declared;
            $permissions[] = null === $permission->domain && null !== $domain ? $permission->in($domain) : $permission;
        }

        return $permissions;
    }
}
