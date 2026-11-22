<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

/**
 * `2.3`, or with a patch, `2.2.9`. The shop's is configured, not discovered.
 */
final readonly class SyliusVersion implements \Stringable
{
    public int $major;

    public int $minor;

    public int $patch;

    public function __construct(string $version)
    {
        if (1 !== preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', trim($version), $parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a Sylius version: a major and a minor, a patch may follow (2.3, 2.2.9).', $version));
        }

        $this->major = (int) $parts[1];
        $this->minor = (int) $parts[2];
        $this->patch = (int) ($parts[3] ?? 0);
    }

    public function isAtLeast(self $other): bool
    {
        return [$this->major, $this->minor, $this->patch] >= [$other->major, $other->minor, $other->patch];
    }

    /**
     * `2.2` for 2.2.9.
     */
    public function line(): string
    {
        return $this->major . '.' . $this->minor;
    }

    public function __toString(): string
    {
        return $this->major . '.' . $this->minor . (0 === $this->patch ? '' : '.' . $this->patch);
    }
}
