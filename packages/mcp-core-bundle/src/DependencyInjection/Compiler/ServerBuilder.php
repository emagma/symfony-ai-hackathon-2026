<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The id symfony/mcp-bundle gives the builder of the server the core attaches to (`emagma_mcp_core.server`).
 *
 * @internal
 */
final class ServerBuilder
{
    private function __construct()
    {
    }

    public static function id(ContainerBuilder $container): string
    {
        $server = $container->getParameter('emagma_mcp_core.server');
        \assert(\is_string($server));

        return \sprintf('mcp.server.%s.builder', $server);
    }
}
