<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Privacy;

/**
 * A static holder because `jsonSerialize()` receives no context; reset with the kernel so a worker carries no caller's mode over.
 * Unconfigured means no MCP caller in the loop (console, unit test) and renders in clear.
 */
final class PrivacyContext
{
    private static ?self $current = null;

    private bool $reveal = false;

    private function __construct(
        private readonly PrivacyMode $mode,
        private readonly bool $revealAllowed,
        private readonly ?Pseudonymiser $pseudonymiser,
    ) {
    }

    public static function configure(PrivacyMode $mode, Pseudonymiser $pseudonymiser, bool $revealAllowed = true): void
    {
        self::$current = new self($mode, $revealAllowed, $pseudonymiser);
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function mode(): PrivacyMode
    {
        return null === self::$current ? PrivacyMode::Off : self::$current->mode;
    }

    public static function hides(): bool
    {
        return PrivacyMode::Pseudonymise === self::mode() && null !== self::$current && !self::$current->reveal;
    }

    /**
     * `reveal: false` on the client refuses even a caller holding the grant.
     */
    public static function revealAllowed(): bool
    {
        return null === self::$current || self::$current->revealAllowed;
    }

    public static function revealThisResponse(): void
    {
        if (null !== self::$current) {
            self::$current->reveal = true;
        }
    }

    public static function token(string $property, string $value): string
    {
        $pseudonymiser = self::$current?->pseudonymiser;
        \assert(null !== $pseudonymiser, 'a configured pseudonymising context always has one');

        return $pseudonymiser->tokenFor($property, $value);
    }
}
