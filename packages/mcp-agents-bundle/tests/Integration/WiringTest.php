<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Integration;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\EmagmaMcpAgentsBundle;
use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Schedule\AgentsSchedule;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\RunAgentHandler;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\ByHandAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\EveryFiveMinutesAgent;
use Emagma\McpAgentsBundle\Tool\ListAgentReports;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** The bundle as the container of a host builds it: the agents autoconfigured, the transport, the schedule, the tool. */
#[CoversClass(EmagmaMcpAgentsBundle::class)]
#[CoversClass(AgentRunner::class)]
#[CoversClass(AgentsSchedule::class)]
#[CoversClass(RunAgentHandler::class)]
#[CoversClass(AgentAlerter::class)]
final class WiringTest extends DatabaseTestCase
{
    public function testAnAgentIsFoundByItsInterfaceAloneAndPlayedUnderItsName(): void
    {
        $runner = $this->service(AgentRunner::class, 'test.runner');

        self::assertTrue($runner->has('by_hand'));
        self::assertTrue($runner->has('every_five_minutes'));
        self::assertSame(['runs' => 1], $runner->run('by_hand'));
    }

    public function testATriggerGoesThroughTheTransportOfTheBundleAndItsHandlerPlaysThePass(): void
    {
        $runner = $this->service(AgentRunner::class, 'test.runner');
        $runner->trigger('every_five_minutes');

        $transport = self::getContainer()->get('messenger.transport.mcp_agents');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        self::assertCount(1, $transport->getSent());
        self::assertEquals(new RunAgent('every_five_minutes'), $transport->getSent()[0]->getMessage());

        $this->service(RunAgentHandler::class, 'test.run_agent_handler')(new RunAgent('by_hand'));
        self::assertSame(1, $this->service(ByHandAgent::class)->runs);
    }

    public function testOnlyTheAgentThatRunsOnItsOwnIsScheduled(): void
    {
        $schedule = $this->service(AgentsSchedule::class, 'test.schedule_provider')->getSchedule();

        self::assertCount(1, $schedule->getRecurringMessages());
        self::assertSame('every 5 minutes', (string) $schedule->getRecurringMessages()[0]->getTrigger());
        self::assertInstanceOf(EveryFiveMinutesAgent::class, $this->service(EveryFiveMinutesAgent::class));
    }

    public function testTheAlertsFollowTheConfiguration(): void
    {
        $alerter = $this->service(AgentAlerter::class, 'test.alerter');

        $low = AgentReport::of('fraud', 'evt-2', 2, 'customer', '2', Level::Low, [], 'Nothing.', new \DateTimeImmutable());

        self::assertFalse($alerter->alert($low), 'below alert_from (medium): the journal only');
        self::assertNull($low->getNotifiedAt());
    }

    public function testTheToolIsExposedOnTheServer(): void
    {
        $map = $this->service(ToolClassMap::class, 'test.tool_class_map');

        self::assertSame(ListAgentReports::class, $map->classOf('list_agent_reports'));
    }
}
