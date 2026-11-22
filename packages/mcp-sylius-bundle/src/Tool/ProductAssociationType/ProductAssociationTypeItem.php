<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociationType;

/**
 * @internal
 */
final class ProductAssociationTypeItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'name' => ['type' => 'string', 'description' => 'The name in `locale`. Required on a creation, where it is copied to every enabled locale unless `names` says otherwise.'],
            'names' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Per-locale names, {locale: name}; on a creation they override the copy of `name`, on an update each one is written.'],
            'locale' => ['type' => 'string', 'description' => 'The locale `name` targets; defaults to the server locale.'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
