<?php

declare(strict_types=1);

namespace Emagma\McpSyliusV2Bundle;

use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;

/**
 * A new 2.x minor is listed here once its routes are checked.
 */
final readonly class Sylius2Line implements SyliusLineInterface
{
    public const array VERSIONS = ['2.0', '2.1', '2.2', '2.3'];

    public function versions(): array
    {
        return self::VERSIONS;
    }

    public function adapter(string $apiUrl): SyliusApiAdapterInterface
    {
        return new Sylius2Adapter($apiUrl);
    }
}
