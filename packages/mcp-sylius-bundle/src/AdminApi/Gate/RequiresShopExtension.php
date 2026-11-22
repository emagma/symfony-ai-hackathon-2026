<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Gate;

/**
 * The tool is hidden and its calls refused (`shop_extension_disabled`) unless the extension is active,
 * whatever the caller's grants.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RequiresShopExtension
{
    public function __construct(
        public string $name,
    ) {
    }
}
