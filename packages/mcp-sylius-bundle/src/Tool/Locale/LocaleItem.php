<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Locale;

/**
 * @internal
 */
final class LocaleItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'locale code, e.g. "it_IT"; the item is matched on it, and it is all a locale has.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
