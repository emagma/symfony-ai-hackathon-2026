<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Integration\Fixture;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;

/**
 * A line serving 2.3 with the default layout: what a line bundle brings, tagged by autoconfiguration.
 */
final readonly class TestLine implements SyliusLineInterface
{
    public function versions(): array
    {
        return ['2.3'];
    }

    public function adapter(string $apiUrl): SyliusApiAdapterInterface
    {
        return new DefaultLayoutAdapter($apiUrl);
    }
}
