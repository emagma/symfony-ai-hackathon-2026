<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit\DependencyInjection;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\DependencyInjection\Compiler\ScheduledAgentsPass;
use Emagma\McpAgentsBundle\Schedule\AgentsSchedule;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\RunAgentHandler;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\ByHandAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\EveryFiveMinutesAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBus;

#[CoversClass(ScheduledAgentsPass::class)]
final class ScheduledAgentsPassTest extends TestCase
{
    public function testTheScheduleAndTheRunnerGetTheAgentsByName(): void
    {
        $container = $this->container();
        $container->register('agent.five', EveryFiveMinutesAgent::class)->setPublic(true)->addTag(ScheduledAgentsPass::TAG);
        $container->register('agent.by_hand', ByHandAgent::class)->addTag(ScheduledAgentsPass::TAG);

        $container->compile();

        $schedule = $container->get('emagma_mcp_agents.schedule_provider');
        self::assertInstanceOf(AgentsSchedule::class, $schedule);
        self::assertCount(1, $schedule->getSchedule()->getRecurringMessages());

        $handler = $container->get(RunAgentHandler::class);
        self::assertInstanceOf(RunAgentHandler::class, $handler);
        $five = $container->get('agent.five');
        self::assertInstanceOf(EveryFiveMinutesAgent::class, $five);
        $handler(new RunAgent('every_five_minutes'));
        self::assertSame(1, $five->runs);
    }

    public function testTwoAgentsWithTheSameNameFailWhenTheContainerIsBuilt(): void
    {
        $container = $this->container();
        $container->register('agent.one', ByHandAgent::class)->addTag(ScheduledAgentsPass::TAG);
        $container->register('agent.two', ByHandAgent::class)->addTag(ScheduledAgentsPass::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two background agents are named "by_hand"');

        $container->compile();
    }

    public function testATaggedServiceThatIsNoAgentFails(): void
    {
        $container = $this->container();
        $container->register('not_an_agent', \stdClass::class)->addTag(ScheduledAgentsPass::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ScheduledAgentInterface::class);

        $container->compile();
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('locks', LockFactory::class)->addArgument(new Reference('lock_store'));
        $container->register('lock_store', InMemoryStore::class);
        $container->register('emagma_mcp_agents.schedule_provider', AgentsSchedule::class)->setPublic(true)
            ->setArgument('$state', new Reference('state'))->setArgument('$locks', new Reference('locks'));
        $container->register('state', ArrayAdapter::class);
        $container->register('emagma_mcp_agents.runner', AgentRunner::class)
            ->setArgument('$bus', new Reference('bus'))->setArgument('$locks', new Reference('locks'));
        $container->register('bus', MessageBus::class);
        $container->register(RunAgentHandler::class, RunAgentHandler::class)->setPublic(true)
            ->setArgument('$runner', new Reference('emagma_mcp_agents.runner'));
        $container->addCompilerPass(new ScheduledAgentsPass());

        return $container;
    }
}
