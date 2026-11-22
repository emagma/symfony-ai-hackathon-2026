<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\FraudCheckerAgent;

use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\SymfonyAiHackathon2026Bundle\EventListener\NewAccountListener;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NewAccountListener::class)]
final class NewAccountListenerTest extends TestCase
{
    public function testANewAccountTriggersTheFraudAgent(): void
    {
        $runner = new Runner(self::fraud());

        (new NewAccountListener($runner->runner))(new ShopEventRecorded('customer.created', '42', 7));

        self::assertEquals([new RunAgent('fraud')], $runner->dispatched);
    }

    public function testAnotherEventTriggersNothing(): void
    {
        $runner = new Runner(self::fraud());

        (new NewAccountListener($runner->runner))(new ShopEventRecorded('order.placed', '9', 8));

        self::assertSame([], $runner->dispatched);
    }

    public function testAnAgentNotEnabledIsTriggeredByNothing(): void
    {
        $runner = new Runner(self::fraud());

        (new NewAccountListener($runner->runner, enabled: false))(new ShopEventRecorded('customer.created', '42', 7));

        self::assertSame([], $runner->dispatched);
    }

    /**
     * An agent named as the fraud agent: the listener only knows its name.
     */
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
                return ['analysed' => 1, 'reported' => 0, 'alerted' => 0];
            }
        };
    }
}
