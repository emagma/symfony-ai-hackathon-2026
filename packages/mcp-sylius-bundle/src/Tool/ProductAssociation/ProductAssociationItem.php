<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociation;

/**
 * @internal
 */
final class ProductAssociationItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'ownerCode' => ['type' => 'string', 'description' => 'Code of the product the association hangs off. With typeCode, the key the item is matched on: a product has one association per type.'],
            'typeCode' => ['type' => 'string', 'description' => 'Code of an existing association type.'],
            'associatedProductCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'The products shown; every code must exist. REPLACES the list on an existing association. Required on a creation; to remove an association use delete_product_associations.'],
        ],
        'required' => ['ownerCode', 'typeCode'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
