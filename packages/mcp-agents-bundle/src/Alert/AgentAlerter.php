<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Alert;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Psr\Clock\ClockInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

/**
 * Raises an analysis that deserves it through Symfony Notifier: the channel (email by default, Slack, ...)
 * is the host's `framework.notifier.channel_policy`, never the agent's business.
 */
final readonly class AgentAlerter
{
    /**
     * @param list<string|null> $recipients email addresses, an entry may hold several separated by commas; none, and nothing is raised
     * @param string       $sender     the From of the alerts sent by email
     * @param Level        $from       the lowest level that is raised; the others stay in the journal
     */
    public function __construct(
        private NotifierInterface $notifier,
        private ClockInterface $clock,
        private array $recipients,
        private string $sender = 'mcp-agents@localhost',
        private Level $from = Level::High,
    ) {
    }

    /**
     * True when an alert was sent. A report is raised once.
     */
    public function alert(AgentReport $report): bool
    {
        // An entry may hold several addresses: the default is one environment variable, comma-separated,
        // which is only known here, at runtime.
        $recipients = [];
        foreach ($this->recipients as $entry) {
            foreach (explode(',', (string) $entry) as $email) {
                if ('' !== $email = trim($email)) {
                    $recipients[] = $email;
                }
            }
        }
        if ([] === $recipients || $report->getLevel()->rank() < $this->from->rank() || null !== $report->getNotifiedAt()) {
            return false;
        }

        $notification = (new AgentAlertNotification(\sprintf(
            '[%s · %s] %s #%s',
            $report->getAgent(),
            $report->getLevel()->value,
            $report->getSubjectType(),
            $report->getSubjectId(),
        ), $this->sender))
            ->content($report->getExplanation() . "\n\n" . implode("\n", array_map(static fn (string $signal): string => '- ' . $signal, $report->getSignals())))
            ->importance(match ($report->getLevel()) {
                Level::High => Notification::IMPORTANCE_HIGH,
                Level::Medium => Notification::IMPORTANCE_MEDIUM,
                Level::Low => Notification::IMPORTANCE_LOW,
            });

        $this->notifier->send($notification, ...array_map(static fn (string $email): Recipient => new Recipient($email), $recipients));
        $report->markNotified($this->clock->now());

        return true;
    }
}
