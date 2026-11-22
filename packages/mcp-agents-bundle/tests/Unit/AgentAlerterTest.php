<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit;

use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Alert\AgentAlertNotification;
use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

#[CoversClass(AgentAlerter::class)]
final class AgentAlerterTest extends TestCase
{
    public function testAHighLevelAnalysisIsRaisedOnceToTheAdminRecipients(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::once())->method('send')->with(
            self::callback(static function (Notification $notification): bool {
                self::assertSame('[fraud · high] customer #42', $notification->getSubject());
                self::assertStringContainsString('- The email domain is a disposable address service.', $notification->getContent());
                self::assertSame(Notification::IMPORTANCE_HIGH, $notification->getImportance());
                self::assertInstanceOf(AgentAlertNotification::class, $notification);
                $email = $notification->asEmailMessage(new Recipient('ops@example.com'))->getMessage();
                self::assertInstanceOf(Email::class, $email);
                self::assertSame('mcp-agents@localhost', $email->getFrom()[0]->getAddress());
                self::assertStringContainsString('The account looks disposable.', (string) $email->getTextBody());

                return true;
            }),
            self::callback(static fn (Recipient $recipient): bool => 'ops@example.com' === $recipient->getEmail()),
        );
        $alerter = new AgentAlerter($notifier, new MockClock(), ['ops@example.com', '']);
        $report = $this->report(Level::High);

        self::assertTrue($alerter->alert($report));
        self::assertNotNull($report->getNotifiedAt());
        self::assertFalse($alerter->alert($report), 'a report is raised once');
    }

    public function testOneEntryMayHoldSeveralAddressesAsTheEnvironmentVariableDoes(): void
    {
        $told = [];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::once())->method('send')->willReturnCallback(static function (Notification $notification, Recipient ...$recipients) use (&$told): void {
            $told = array_map(static fn (Recipient $recipient): string => $recipient->getEmail(), $recipients);
        });

        // What `%env(default::MCP_AGENT_ALERT_EMAIL)%` resolves to: one text, or null when unset.
        self::assertTrue((new AgentAlerter($notifier, new MockClock(), ['ops@example.com, fraud@example.com ,', null]))->alert($this->report(Level::High)));
        self::assertSame(['ops@example.com', 'fraud@example.com'], $told);
    }

    public function testBelowTheConfiguredLevelAnAnalysisStaysInTheJournal(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('send');
        $alerter = new AgentAlerter($notifier, new MockClock(), ['ops@example.com']);

        self::assertFalse($alerter->alert($this->report(Level::Medium)));
        self::assertFalse($alerter->alert($this->report(Level::Low)));
        self::assertFalse((new AgentAlerter($notifier, new MockClock(), []))->alert($this->report(Level::High)), 'nobody to tell');
    }

    private function report(Level $level): AgentReport
    {
        return AgentReport::of('fraud', 'evt-1', 1, 'customer', '42', $level, ['The email domain is a disposable address service.'], 'The account looks disposable.', new \DateTimeImmutable());
    }
}
