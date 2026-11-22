<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection\Compiler;

use Emagma\McpCoreBundle\Instruction\LiteralInstruction;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Makes McpInstructions the configurator of the server's builder: the `instructions` of the server's configuration,
 * when set, become one instruction of priority 0 among the tagged ones.
 *
 * @internal
 */
final class InstructionsPass implements CompilerPassInterface
{
    public const string TAG = 'emagma_mcp_core.instruction';

    public function process(ContainerBuilder $container): void
    {
        $builder = ServerBuilder::id($container);
        if (!$container->hasDefinition($builder) || !$container->hasDefinition('emagma_mcp_core.instructions')) {
            return;
        }
        $definition = $container->getDefinition($builder);

        $calls = [];
        /** @var list<array{0: string, 1: array<mixed>}> $existing */
        $existing = $definition->getMethodCalls();
        foreach ($existing as [$method, $arguments]) {
            if ('setInstructions' === $method) {
                $literal = $arguments[0] ?? null;
                if (\is_string($literal) && '' !== trim($literal)) {
                    $container->register('emagma_mcp_core.instruction.configured', LiteralInstruction::class)
                        ->setArguments([$literal])
                        ->addTag(self::TAG, ['priority' => 0]);
                }

                continue;
            }
            $calls[] = [$method, $arguments];
        }
        $definition->setMethodCalls($calls);

        if (null !== $definition->getConfigurator()) {
            throw new LogicException(\sprintf('The MCP server builder "%s" already has a configurator: its instructions cannot be assembled.', $builder));
        }
        $definition->setConfigurator([new Reference('emagma_mcp_core.instructions'), 'configure']);
    }
}
