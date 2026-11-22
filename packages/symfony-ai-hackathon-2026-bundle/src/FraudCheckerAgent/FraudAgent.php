<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Schedule\ScheduledAgentInterface;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Emagma\SymfonyAiHackathon2026Bundle\Exception\JudgementFailedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the customer accounts the shop created since its last run, and reports the risky ones. It only
 * informs: nothing is changed in the shop. Every account it looked at is in the journal, so none is looked
 * at twice.
 *
 * Woken on each new account (`NewAccountListener`) and by the schedule of the agents. A pass drains what is new,
 * batch after batch, up to a bound: the next pass catches up with the rest.
 *
 * v1 reads the shop with the server's service account, as an administrator would; a token bound to a
 * profile with only the reads it needs is the planned next step.
 *
 * @internal
 */
final readonly class FraudAgent implements ScheduledAgentInterface
{
    public const string AGENT = 'fraud';

    public const string EVENT = 'customer.created';

    /** The accounts read from the shop at once. */
    public const int BATCH = 50;

    /** The accounts one pass analyses at most. */
    public const int MAX_PER_PASS = 1000;

    public function __construct(
        private ShopEventStoreInterface $events,
        private AgentReportStoreInterface $reports,
        private AdminApiClientInterface $client,
        private EmailRiskRules $rules,
        private RiskJudgeInterface $judge,
        private AgentAlerter $alerter,
        private ClockInterface $clock,
        private ?string $every = null,
        private ?LoggerInterface $logger = null,
        private int $maxPerPass = self::MAX_PER_PASS,
    ) {
    }

    public static function name(): string
    {
        return self::AGENT;
    }

    /**
     * Null when the agent is not enabled: it then only runs by hand.
     */
    public function every(): ?string
    {
        return $this->every;
    }

    /**
     * @return array{analysed: int, reported: int, alerted: int}
     */
    public function run(): array
    {
        $summary = ['analysed' => 0, 'reported' => 0, 'alerted' => 0];
        // A cursor of its own: a batch whose reports were all recorded already still moves it on.
        $cursor = $this->reports->lastSequence(self::AGENT);
        $read = 0;
        while ($read < $this->maxPerPass) {
            $limit = min(self::BATCH, $this->maxPerPass - $read);
            $events = $this->events->after(self::EVENT, $cursor, $limit);
            if ([] === $events) {
                break;
            }
            $this->analyseBatch($events, $summary);
            $read += \count($events);
            $cursor = $events[\count($events) - 1]->getSequence();
            if (\count($events) < $limit) {
                break;
            }
        }

        return $summary;
    }

    /**
     * @param non-empty-list<ShopEvent>                         $events
     * @param array{analysed: int, reported: int, alerted: int} $summary
     */
    private function analyseBatch(array $events, array &$summary): void
    {
        $paths = [];
        foreach ($events as $event) {
            if (null !== $event->getSubjectId()) {
                $paths[$event->getSubjectId()] = Route::item(Resource::Customer, $event->getSubjectId());
            }
        }
        $customers = [] === $paths ? [] : $this->client->getDecodedMany($paths);

        foreach ($events as $event) {
            $customer = null === $event->getSubjectId() ? null : ($customers[$event->getSubjectId()] ?? null);
            $report = $this->analyse($event, null !== $customer && true === $customer['ok'] ? $customer['body'] : null);
            if (!$this->reports->record($report)) {
                continue;
            }
            ++$summary['analysed'];
            if ($report->getLevel()->rank() >= Level::Medium->rank()) {
                ++$summary['reported'];
            }
            if ($this->alerter->alert($report)) {
                ++$summary['alerted'];
            }
        }
        $this->reports->save();
    }

    /**
     * @param array<array-key, mixed>|null $customer the Admin API payload, null when it could not be read
     */
    private function analyse(ShopEvent $event, ?array $customer): AgentReport
    {
        $subject = $event->getSubjectId() ?? '?';
        $email = \is_string($customer['email'] ?? null) ? $customer['email'] : null;
        if (null === $email) {
            // Deleted since, or the shop is down: recorded so that the agent moves on.
            return $this->report($event, $subject, Level::Low, [], 'The customer could not be read (deleted since, or the shop did not answer).');
        }
        $firstName = \is_string($customer['firstName'] ?? null) ? $customer['firstName'] : null;
        $lastName = \is_string($customer['lastName'] ?? null) ? $customer['lastName'] : null;

        $assessment = $this->rules->assess($email, $firstName, $lastName);
        if ($assessment->isHigh()) {
            return $this->report($event, $subject, Level::High, $assessment->signals, 'The email address of this new account cannot be a durable personal address.');
        }
        if (!$assessment->isAmbiguous()) {
            return $this->report($event, $subject, Level::Low, $assessment->signals, 'Nothing unusual in the email address of this new account.');
        }

        try {
            $judgement = $this->judge->judge($assessment, null !== $firstName || null !== $lastName);
        } catch (JudgementFailedException $exception) {
            $this->logger?->warning('The fraud agent could not ask its model; the rules\' own reading is reported.', ['customer' => $subject, 'exception' => $exception]);

            return $this->report($event, $subject, Level::Medium, $assessment->signals, 'The rules found signals they cannot settle, and the model could not be asked.');
        }

        return $this->report($event, $subject, $judgement->level, $assessment->signals, $judgement->explanation);
    }

    /**
     * @param list<string> $signals
     */
    private function report(ShopEvent $event, string $subject, Level $level, array $signals, string $explanation): AgentReport
    {
        return AgentReport::of(self::AGENT, $event->getEventId(), $event->getSequence(), 'customer', $subject, $level, $signals, $explanation, $this->clock->now());
    }
}
