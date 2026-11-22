<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Adapter;

/**
 * A resource of Sylius' own Admin API ({@see Resource}), or one a shop extension declares.
 */
interface ApiResource
{
    /**
     * Its name in a route table: `product_option_value`.
     */
    public function routeKey(): string;

    /**
     * Where an item lives unless the line's adapter says otherwise, its last placeholder the key; null without item.
     */
    public function itemTemplate(): ?string;

    /**
     * Null when the collection is the item's template without its key.
     */
    public function collectionTemplate(): ?string;
}
