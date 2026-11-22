<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\RunAgentHandler;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\ByHandAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Fixture\EveryFiveMinutesAgent;
use Emagma\McpAgentsBundle\Tests\Unit\Support\Agents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[CoversClass(AgentRunner::class)]
#[CoversClass(RunAgentHandler::class)]
#[CoversClass(RunAgent::class)]
final class AgentRunnerTest extends TestCase
{
    public function testATriggerPutsRunAgentInTheQueueWithItsDeduplicateStamp(): void
    {
        $agents = new Agents(new EveryFiveMinutesAgent());

        $agents->runner()->trigger('every_five_minutes');

        [$envelope] = $agents->waiting();
        self::assertEquals(new RunAgent('every_five_minutes'), $envelope->getMessage());
        $stamp = $envelope->last(DeduplicateStamp::class);
        self::assertInstanceOf(DeduplicateStamp::class, $stamp);
        self::assertSame('mcp_agent.queued.every_five_minutes', (string) $stamp->getKey());
        self::assertSame(AgentRunner::QUEUED_TTL, $stamp->getTtl());
        self::assertTrue($stamp->onlyDeduplicateInQueue(), 'the key is released when the worker receives the message, before the pass');
    }

    public function testASecondTriggerWhileTheFirstIsWaitingAddsNothing(): void
    {
        $agents = new Agents(new EveryFiveMinutesAgent(), new ByHandAgent());
        $runner = $agents->runner();

        $runner->trigger('every_five_minutes');
        $runner->trigger('every_five_minutes');
        $runner->trigger('by_hand');

        self::assertEquals(
            [new RunAgent('every_five_minutes'), new RunAgent('by_hand')],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $agents->waiting()),
            'one waiting message per agent',
        );
    }

    public function testOnceTheMessageIsReceivedATriggerGoesThroughAgain(): void
    {
        $agents = new Agents(new EveryFiveMinutesAgent());
        $runner = $agents->runner();
        $runner->trigger('every_five_minutes');

        // The worker receives it: what happens during the pass asks for another one.
        $agents->receive($agents->waiting()[0]);
        $runner->trigger('every_five_minutes');

        self::assertCount(1, $agents->waiting());
    }

    public function testATriggerDuringAPassIsNotLost(): void
    {
        $agents = new Agents(new EveryFiveMinutesAgent());
        $runner = $agents->runner();
        $pass = $agents->locks->createLock(AgentRunner::passKey('every_five_minutes'));
        self::assertTrue($pass->acquire());

        $runner->trigger('every_five_minutes');

        self::assertCount(1, $agents->waiting());
    }

    public function testThePassRunsTheAgentUnderItsLockThenReleasesIt(): void
    {
        $agent = new EveryFiveMinutesAgent();
        $agents = new Agents($agent);

        self::assertSame(['runs' => 1], $agents->runner()->run('every_five_minutes'));
        self::assertSame(['runs' => 2], $agents->runner()->run('every_five_minutes'));
        self::assertFalse($agents->locks->createLock(AgentRunner::passKey('every_five_minutes'))->isAcquired());
    }

    public function testAPassIsSkippedWhileAnotherHoldsTheLock(): void
    {
        $agent = new EveryFiveMinutesAgent();
        $agents = new Agents($agent);
        $other = $agents->locks->createLock(AgentRunner::passKey('every_five_minutes'));
        self::assertTrue($other->acquire());

        self::assertNull($agents->runner()->run('every_five_minutes'));
        (new RunAgentHandler($agents->runner()))(new RunAgent('every_five_minutes'));

        self::assertSame(0, $agent->runs);
        $other->release();
        (new RunAgentHandler($agents->runner()))(new RunAgent('every_five_minutes'));
        self::assertSame(1, $agent->runs);
    }

    public function testTheHandlerRunsTheAgentItIsNamedAfter(): void
    {
        $agent = new EveryFiveMinutesAgent();
        $other = new ByHandAgent();

        (new RunAgentHandler((new Agents($agent, $other))->runner()))(new RunAgent('by_hand'));

        self::assertSame(0, $agent->runs);
        self::assertSame(1, $other->runs);
    }

    public function testAnUnknownNameIsLoggedAndDroppedByTheHandler(): void
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = \is_string($level) ? $level : '?';
            }
        };
        $agent = new EveryFiveMinutesAgent();

        (new RunAgentHandler((new Agents($agent))->runner(), $logger))(new RunAgent('removed_since'));

        self::assertSame(0, $agent->runs);
        self::assertSame(['warning'], $logger->logged);
    }

    public function testAnUnknownNameCannotBeTriggered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No background agent is named "removed_since"');

        (new Agents(new EveryFiveMinutesAgent()))->runner()->trigger('removed_since');
    }
}
