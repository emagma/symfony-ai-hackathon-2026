<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Taxon;

/**
 * @internal
 */
final class TaxonItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale name, slug and description target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation, where it is copied to every enabled locale.'],
            'slug' => ['type' => 'string', 'description' => 'URL slug in `locale`; Sylius derives one from the name when absent on a creation.'],
            'description' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given.'],
            'position' => ['type' => 'integer', 'description' => 'Gedmo Sortable position among siblings: clamped by the shop, siblings renumbered.'],
            'parentCode' => ['type' => 'string', 'description' => 'Code of the parent taxon, which must ALREADY exist: a parent created in the same batch is unknown when the batch is planned, create parents in an earlier call. Absent on a creation means top level; on an update, moves the taxon (explicit slugs are kept).'],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
