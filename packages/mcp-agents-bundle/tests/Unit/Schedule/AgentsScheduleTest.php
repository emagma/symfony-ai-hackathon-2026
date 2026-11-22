<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit\Schedule;

use Emagma\McpAgentsBundle\Schedule\AgentsSchedule;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\ByHandAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\EveryFiveMinutesAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Support\Agents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(AgentsSchedule::class)]
final class AgentsScheduleTest extends TestCase
{
    public function testOnlyTheAgentsThatRunOnTheirOwnAreScheduledAndOnlyRedispatchedToTheirTransport(): void
    {
        $messages = $this->schedule()->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        self::assertSame('every 5 minutes', (string) $messages[0]->getTrigger());
        self::assertEquals([new RedispatchMessage(new RunAgent('every_five_minutes'), 'mcp_agents')], self::messagesOf($messages[0]));
    }

    public function testTheScheduleIsStatefulAndLocked(): void
    {
        $state = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());

        $schedule = (new AgentsSchedule(Agents::locator(new EveryFiveMinutesAgent()), $state, $locks))->getSchedule();

        self::assertSame($state, $schedule->getState(), 'the last run is kept across restarts');
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun(), 'after a stop, one run, not every missed one');
        $lock = $schedule->getLock();
        self::assertInstanceOf(LockInterface::class, $lock);
        self::assertTrue($lock->acquire());
        self::assertFalse($locks->createLock(AgentsSchedule::LOCK)->acquire(), 'a second process of the schedule waits');
    }

    private function schedule(): AgentsSchedule
    {
        return new AgentsSchedule(Agents::locator(new EveryFiveMinutesAgent(), new ByHandAgent()), new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }

    /**
     * @return list<object>
     */
    private static function messagesOf(RecurringMessage $message): array
    {
        $messages = [];
        foreach ($message->getMessages(new MessageContext('mcp_agents', $message->getId(), $message->getTrigger(), new \DateTimeImmutable())) as $one) {
            \assert(\is_object($one));
            $messages[] = $one;
        }

        return $messages;
    }
}
