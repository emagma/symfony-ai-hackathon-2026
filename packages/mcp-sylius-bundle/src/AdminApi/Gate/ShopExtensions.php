<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Gate;

/**
 * The active shop extensions, read from the environment when the service is built (no cache clear).
 * An unknown name throws: a typo would silently hide tools.
 */
final class ShopExtensions
{
    /** @var list<string> */
    private readonly array $active;

    /**
     * @param list<string> $known the names the installed bundles declare (tag `emagma_mcp_sylius.shop_extension`)
     */
    public function __construct(string $extensions = '', array $known = [])
    {
        $active = [];
        foreach (explode(',', $extensions) as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            if (!\in_array($name, $known, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'MCP_SHOP_EXTENSIONS names an unknown shop extension "%s" (value: "%s"). Known extensions: %s.',
                    $name,
                    $extensions,
                    [] === $known ? '(none: no installed bundle declares one)' : implode(', ', $known),
                ));
            }
            $active[$name] = $name;
        }

        $this->active = array_values($active);
    }

    public function isActive(string $name): bool
    {
        return \in_array($name, $this->active, true);
    }

    /**
     * @return list<string> in the order they were configured, without duplicates
     */
    public function active(): array
    {
        return $this->active;
    }
}
