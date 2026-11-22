<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Unit;

use Emagma\McpTasksBundle\EmagmaMcpTasksBundle;
use Emagma\McpTasksMessengerBundle\EmagmaMcpTasksMessengerBundle;
use Emagma\McpTasksMessengerBundle\EventListener\InterruptedTasksListener;
use Emagma\McpTasksMessengerBundle\Handler\RunStepHandler;
use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Emagma\McpTasksMessengerBundle\MessengerOrchestrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

#[CoversClass(EmagmaMcpTasksMessengerBundle::class)]
final class EmagmaMcpTasksMessengerBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_tasks_messenger', $this->extension()->getAlias());
    }

    public function testItIsTheOrchestratorOfTheTasksWithAQueueOfItsOwnNeverRetriedAndKeptOnFailure(): void
    {
        $container = $this->container();
        $container->registerExtension((new EmagmaMcpTasksBundle())->getContainerExtension());
        $container->registerExtension(new FrameworkExtension());

        $this->prepend($container);

        self::assertSame([['orchestrator' => EmagmaMcpTasksMessengerBundle::ORCHESTRATOR]], $container->getExtensionConfig('emagma_mcp_tasks'));
        self::assertSame([['messenger' => [
            'transports' => [
                'mcp_tasks' => ['dsn' => '%env(MCP_TASKS_TRANSPORT_DSN)%', 'retry_strategy' => ['max_retries' => 0], 'failure_transport' => 'failed_mcp_tasks'],
                'failed_mcp_tasks' => ['dsn' => 'doctrine://default?queue_name=failed_mcp_tasks'],
            ],
            'routing' => [RunStep::class => 'mcp_tasks'],
        ]]], $container->getExtensionConfig('framework'));
        self::assertSame(EmagmaMcpTasksMessengerBundle::DEFAULT_DSN, self::dsn($container));
    }

    public function testTheHostKeepsItsOwnTransport(): void
    {
        $container = $this->container();
        $container->setParameter('env(MCP_TASKS_TRANSPORT_DSN)', 'redis://queue');

        $this->prepend($container);

        self::assertSame('redis://queue', self::dsn($container));
        self::assertSame([], $container->getExtensionConfig('emagma_mcp_tasks'));
    }

    public function testItsServices(): void
    {
        $container = $this->container();
        $this->extension()->load([[]], $container);

        self::assertSame(MessengerOrchestrator::class, $container->getDefinition(EmagmaMcpTasksMessengerBundle::ORCHESTRATOR)->getClass());
        self::assertSame(EmagmaMcpTasksMessengerBundle::ORCHESTRATOR, (string) $container->getAlias(MessengerOrchestrator::class));
        self::assertTrue($container->getDefinition('emagma_mcp_tasks_messenger.run_step_handler')->hasTag('messenger.message_handler'));
        self::assertSame(RunStepHandler::class, $container->getDefinition('emagma_mcp_tasks_messenger.run_step_handler')->getClass());
        self::assertSame(InterruptedTasksListener::class, $container->getDefinition('emagma_mcp_tasks_messenger.interrupted_tasks_listener')->getClass());
        self::assertTrue($container->getDefinition('emagma_mcp_tasks_messenger.interrupted_tasks_listener')->hasTag('kernel.event_listener'));
    }

    private function extension(): ExtensionInterface
    {
        return (new EmagmaMcpTasksMessengerBundle())->getContainerExtension() ?? self::fail('No extension');
    }

    private function prepend(ContainerBuilder $container): void
    {
        $extension = $this->extension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);

        return $container;
    }

    /**
     * The default as set: getParameter() answers a placeholder for an env().
     */
    private static function dsn(ContainerBuilder $container): mixed
    {
        return $container->getParameterBag()->all()['env(MCP_TASKS_TRANSPORT_DSN)'] ?? null;
    }
}
