<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Grants are `mcp:<domain>:<resource>:<action>` strings. The domain is the business solution a bundle covers
 * (`sylius`, `sylius_cms`, `core`), never the server that hosts it: splitting or merging servers changes no grant.
 * The resource `all` covers every resource of its domain, the domain `all` every domain.
 * A write or delete grant also satisfies read; write and delete are independent.
 */
final readonly class Grants
{
    public const string WILDCARD = 'all';

    private const string PREFIX = 'mcp:';

    /**
     * @param array<string, true> $granted keys are `<domain>:<resource>:<action>`
     */
    private function __construct(
        private array $granted,
        private bool $allowAll = false,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Explicit opt-in only (stdio/dev/tests): never a fallback.
     */
    public static function allowAll(): self
    {
        return new self([], true);
    }

    /**
     * @param list<string> $grants entries not matching `mcp:<domain>:<resource>:<action>` are ignored
     */
    public static function fromStrings(array $grants): self
    {
        $granted = [];
        foreach ($grants as $grant) {
            if (!str_starts_with($grant, self::PREFIX)) {
                continue;
            }
            $parts = explode(':', substr($grant, \strlen(self::PREFIX)));
            if (3 !== \count($parts) || '' === $parts[0] || '' === $parts[1] || null === Action::tryFrom($parts[2])) {
                continue;
            }
            $granted[$parts[0] . ':' . $parts[1] . ':' . $parts[2]] = true;
        }

        return new self($granted);
    }

    public function isAllowAll(): bool
    {
        return $this->allowAll;
    }

    public function allows(Permission $permission): bool
    {
        if ($this->allowAll) {
            return true;
        }

        if (null === $permission->domain) {
            return false;
        }

        foreach ($this->actionsSatisfying($permission->action) as $action) {
            foreach ([$permission->domain, self::WILDCARD] as $domain) {
                foreach ([$permission->resource, self::WILDCARD] as $resource) {
                    // `mcp:all:<resource>:<action>` would grant a resource name across unrelated domains.
                    if (self::WILDCARD === $domain && self::WILDCARD !== $resource) {
                        continue;
                    }
                    if (isset($this->granted[$domain . ':' . $resource . ':' . $action->value])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Fail-closed: an empty list (undeclared tool) is never allowed under real grants.
     *
     * @param list<Permission> $permissions
     */
    public function allowsAll(array $permissions): bool
    {
        if ($this->allowAll) {
            return true;
        }
        if ([] === $permissions) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Action>
     */
    private function actionsSatisfying(Action $required): array
    {
        return match ($required) {
            Action::Read => [Action::Read, Action::Write, Action::Delete],
            Action::Write => [Action::Write],
            Action::Delete => [Action::Delete],
            Action::Reveal => [Action::Reveal],
        };
    }
}
