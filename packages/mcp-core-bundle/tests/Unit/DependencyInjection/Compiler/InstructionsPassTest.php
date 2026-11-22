<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\DependencyInjection\Compiler;

use Emagma\McpCoreBundle\DependencyInjection\Compiler\InstructionsPass;
use Emagma\McpCoreBundle\Instruction\LiteralInstruction;
use Emagma\McpCoreBundle\Instruction\McpInstructionInterface;
use Emagma\McpCoreBundle\Instruction\McpInstructions;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(InstructionsPass::class)]
final class InstructionsPassTest extends TestCase
{
    public function testEveryTaggedInstructionReachesTheServerHighestPriorityFirst(): void
    {
        $container = $this->container(null);
        $container->register('app.business', LiteralInstruction::class)->setArguments(['Business.'])->addTag(InstructionsPass::TAG);
        $container->register('app.backend', LiteralInstruction::class)->setArguments(['Backend.'])->addTag(InstructionsPass::TAG, ['priority' => 100]);
        $container->register('app.last', LiteralInstruction::class)->setArguments(['Last.'])->addTag(InstructionsPass::TAG, ['priority' => -10]);

        $container->addCompilerPass(new InstructionsPass());
        $container->compile();

        $instructions = $container->get('emagma_mcp_core.instructions');
        self::assertInstanceOf(McpInstructions::class, $instructions);
        self::assertSame("Backend.\n\nBusiness.\n\nLast.", $instructions->text());
    }

    public function testTheInstructionsWrittenInTheServersConfigurationAreKept(): void
    {
        $container = $this->container('Written by the host.');
        $container->register('app.backend', LiteralInstruction::class)->setArguments(['Backend.'])->addTag(InstructionsPass::TAG, ['priority' => 100]);

        $container->addCompilerPass(new InstructionsPass());
        $container->compile();

        $instructions = $container->get('emagma_mcp_core.instructions');
        self::assertInstanceOf(McpInstructions::class, $instructions);
        self::assertSame("Backend.\n\nWritten by the host.", $instructions->text());
        self::assertNotContains('setInstructions', array_column($container->getDefinition('mcp.server.default.builder')->getMethodCalls(), 0));
    }

    private function container(?string $literal): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('emagma_mcp_core.server', 'default');
        $container->register('mcp.server.default.builder', Builder::class)->setPublic(true)->addMethodCall('setInstructions', [$literal]);
        $container->register('emagma_mcp_core.instructions', McpInstructions::class)->setPublic(true)
            ->setArguments([new TaggedIteratorArgument(InstructionsPass::TAG)]);
        $container->registerForAutoconfiguration(McpInstructionInterface::class);

        return $container;
    }
}
