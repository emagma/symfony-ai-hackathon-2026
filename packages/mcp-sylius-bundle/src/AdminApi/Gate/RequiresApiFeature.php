<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Gate;

use Emagma\McpSyliusBundle\AdminApi\ApiFeature;

/**
 * The tool is hidden and its calls refused (`sylius_version_unsupported`) on a Sylius line that lacks
 * the feature, whatever the caller's grants. Repeatable: every feature named must be available.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class RequiresApiFeature
{
    public function __construct(
        public ApiFeature $feature,
    ) {
    }
}
