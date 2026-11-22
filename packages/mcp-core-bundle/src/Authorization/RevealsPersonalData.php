<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

/**
 * Marks a tool whose `reveal: true` argument serves personal identifiers in clear to a caller holding `mcp:core:pii:reveal`.
 * Not a Permission: the tool stays readable without the grant, which the tool checks itself.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RevealsPersonalData
{
    public const string DOMAIN = 'core';

    public const string RESOURCE = 'pii';

    public static function permission(): Permission
    {
        return new Permission(self::RESOURCE, Action::Reveal, self::DOMAIN);
    }
}
