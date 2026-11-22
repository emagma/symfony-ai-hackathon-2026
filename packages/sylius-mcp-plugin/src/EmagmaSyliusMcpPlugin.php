<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin;

use Emagma\SyliusMcpPlugin\DependencyInjection\Compiler\FixTranslatableDenormalizerPass;
use Emagma\SyliusMcpPlugin\DependencyInjection\Compiler\ResolveLeanMapsPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * What a Sylius shop needs so that the MCP server can drive it well: lean API
 * payloads and the filters the MCP tools rely on, and the service account the
 * server calls the Admin API with. Nothing MCP runs in here.
 */
final class EmagmaSyliusMcpPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ResolveLeanMapsPass());
        $container->addCompilerPass(new FixTranslatableDenormalizerPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
