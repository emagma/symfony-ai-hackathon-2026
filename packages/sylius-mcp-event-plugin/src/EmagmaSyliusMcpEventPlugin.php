<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The shop half of the MCP server's events bundle: tells the server what happens in the shop, as signed
 * webhooks. One Symfony and Doctrine listener per event, so it runs on Sylius 1.14 as on 2.x. Nothing MCP
 * runs in here.
 */
final class EmagmaSyliusMcpEventPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
