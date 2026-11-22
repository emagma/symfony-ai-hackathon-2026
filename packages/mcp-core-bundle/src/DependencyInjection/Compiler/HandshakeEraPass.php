<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the server on the handshake era: the 2026-07-28 revision reruns an eliciting tool from the start, and writes elicit midway.
 * Done on the builder: `protocol_versions` would pin the server to one handshake revision.
 *
 * @internal
 */
final class HandshakeEraPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $builder = ServerBuilder::id($container);
        if ($container->hasDefinition($builder)) {
            $container->getDefinition($builder)->addMethodCall('withoutModernEra');
        }
    }
}
