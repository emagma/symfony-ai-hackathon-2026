<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Elicitation;

use Emagma\SyliusAssistantTestChat\Elicitation\ElicitationBroker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ElicitationBroker::class)]
final class ElicitationBrokerTest extends TestCase
{
    public function testTheBrowsersAnswerReachesTheWaitingTurnOnce(): void
    {
        $broker = new ElicitationBroker(new ArrayAdapter());
        $id = $broker->open();

        self::assertTrue($broker->answer($id, 'decline'));
        self::assertSame('decline', $broker->await($id, 1.0, 0.01));
        self::assertFalse($broker->answer($id, 'accept'), 'answered and consumed');
    }

    public function testNoAnswerWithinTheTimeoutReturnsNull(): void
    {
        $broker = new ElicitationBroker(new ArrayAdapter());
        $id = $broker->open();

        $started = microtime(true);
        self::assertNull($broker->await($id, 0.2, 0.05));
        self::assertGreaterThanOrEqual(0.2, microtime(true) - $started);
        self::assertFalse($broker->answer($id, 'accept'), 'too late: the turn moved on');
    }

    public function testOnlyAKnownIdAndAKnownActionAreAccepted(): void
    {
        $broker = new ElicitationBroker(new ArrayAdapter());
        $id = $broker->open();

        self::assertFalse($broker->answer($id, 'yes-please'));
        self::assertFalse($broker->answer(str_repeat('0', 32), 'accept'));
        self::assertFalse($broker->answer('../../etc/passwd', 'accept'));
        self::assertSame(32, \strlen($id));
    }
}
