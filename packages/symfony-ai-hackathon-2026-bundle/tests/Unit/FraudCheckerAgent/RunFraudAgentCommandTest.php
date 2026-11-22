<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\FraudCheckerAgent;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Emagma\SymfonyAiHackathon2026Bundle\Command\RunFraudAgentCommand;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RunFraudAgentCommand::class)]
final class RunFraudAgentCommandTest extends TestCase
{
    public function testItRunsAPassAndSaysWhatItFound(): void
    {
        $runner = new Runner(self::fraud());
        $tester = new CommandTester(new RunFraudAgentCommand($runner->runner));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('3 account(s) analysed, 1 reported (medium or high), 1 alert(s) sent.', $tester->getDisplay());
    }

    public function testDuringAPassItSaysSoAndStops(): void
    {
        $runner = new Runner(self::fraud());
        $pass = $runner->locks->createLock(AgentRunner::passKey(FraudAgent::AGENT));
        self::assertTrue($pass->acquire());
        $tester = new CommandTester(new RunFraudAgentCommand($runner->runner));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('A pass of the fraud agent is running', $tester->getDisplay());
    }

    private static function fraud(): ScheduledAgentInterface
    {
        return new class() implements ScheduledAgentInterface {
            public static function name(): string
            {
                return FraudAgent::AGENT;
            }

            public function every(): ?string
            {
                return null;
            }

            public function run(): array
            {
                return ['analysed' => 3, 'reported' => 1, 'alerted' => 1];
            }
        };
    }
}
