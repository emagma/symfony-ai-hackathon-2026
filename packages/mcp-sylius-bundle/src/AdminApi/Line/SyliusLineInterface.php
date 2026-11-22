<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Line;

use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;

/**
 * The Sylius versions one line bundle serves, and their adapter. Tagged `emagma_mcp_sylius.line` by autoconfiguration.
 */
interface SyliusLineInterface
{
    /**
     * Minor versions, a patch never named: `['2.0', '2.1']`.
     *
     * @return list<string>
     */
    public function versions(): array;

    public function adapter(string $apiUrl): SyliusApiAdapterInterface;
}
