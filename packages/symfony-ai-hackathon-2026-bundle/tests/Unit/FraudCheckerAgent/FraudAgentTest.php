<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\FraudCheckerAgent;

use Emagma\McpAgentsBundle\Alert\AgentAlerter;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Testing\InMemoryAgentReportStore;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;
use Emagma\McpSyliusEventsBundle\Testing\InMemoryShopEventStore;
use Emagma\SymfonyAiHackathon2026Bundle\Exception\JudgementFailedException;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\DomainCheckerInterface;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\EmailRiskRules;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\Judgement;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\RiskAssessment;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\RiskJudgeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Notifier\NotifierInterface;

#[CoversClass(FraudAgent::class)]
final class FraudAgentTest extends TestCase
{
    private InMemoryShopEventStore $events;

    private InMemoryAgentReportStore $reports;

    /** @var list<string> the shapes the judge was asked about */
    private array $judged = [];

    protected function setUp(): void
    {
        $this->events = new InMemoryShopEventStore();
        $this->reports = new InMemoryAgentReportStore();
        $this->judged = [];
    }

    public function testEveryNewAccountIsAnalysedOnceAndOnlyTheAmbiguousOnesReachTheModel(): void
    {
        $this->created('evt-1', '1');
        $this->created('evt-2', '2');
        $this->created('evt-3', '3');
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::once())->method('send');
        $agent = $this->agent($notifier);

        $summary = $agent->run();

        self::assertSame(['analysed' => 3, 'reported' => 2, 'alerted' => 1], $summary);
        self::assertSame(['a9a9aa9a9a@example.com'], $this->judged, 'the model sees a shape, never the address, and only for the ambiguous account');

        $byCustomer = [];
        foreach ($this->reports->reports as $report) {
            $byCustomer[$report->getSubjectId()] = $report;
        }
        self::assertSame(Level::Low, $byCustomer['1']->getLevel());
        self::assertSame(Level::High, $byCustomer['2']->getLevel());
        self::assertNotNull($byCustomer['2']->getNotifiedAt());
        self::assertSame(Level::Medium, $byCustomer['3']->getLevel());
        self::assertSame('The judge says medium.', $byCustomer['3']->getExplanation());
        foreach ($this->reports->reports as $report) {
            self::assertStringNotContainsString('@example.com', implode(' ', $report->getSignals()) . $report->getExplanation());
        }

        self::assertSame(['analysed' => 0, 'reported' => 0, 'alerted' => 0], $agent->run(), 'nothing new: nothing is analysed twice');
    }

    public function testWhenTheModelCannotBeAskedTheRulesOwnReadingIsReported(): void
    {
        $this->created('evt-3', '3');

        $this->agent($this->createStub(NotifierInterface::class), judgeFails: true)->run();

        self::assertSame(Level::Medium, $this->reports->reports[0]->getLevel());
        self::assertStringContainsString('the model could not be asked', $this->reports->reports[0]->getExplanation());
    }

    public function testACustomerThatCanNoLongerBeReadIsRecordedSoTheAgentMovesOn(): void
    {
        $this->created('evt-9', '404');
        $agent = $this->agent($this->createStub(NotifierInterface::class));

        self::assertSame(1, $agent->run()['analysed']);
        self::assertSame(Level::Low, $this->reports->reports[0]->getLevel());
        self::assertSame(0, $agent->run()['analysed']);
    }

    public function testAPassDrainsWhatIsNewBatchAfterBatch(): void
    {
        for ($i = 1; $i <= 120; ++$i) {
            $this->created('evt-' . $i, (string) $i);
        }
        $batches = [];

        $summary = $this->plainAgent($batches)->run();

        self::assertSame(120, $summary['analysed'], 'one pass, not one batch every five minutes');
        self::assertSame([50, 50, 20], $batches);
        self::assertSame(['analysed' => 0, 'reported' => 0, 'alerted' => 0], $this->plainAgent($batches)->run());
    }

    public function testAPassStopsAtItsBoundAndTheNextOneCatchesUp(): void
    {
        for ($i = 1; $i <= 120; ++$i) {
            $this->created('evt-' . $i, (string) $i);
        }
        $batches = [];

        self::assertSame(100, $this->plainAgent($batches, maxPerPass: 100)->run()['analysed']);
        self::assertSame([50, 50], $batches);
        self::assertSame(20, $this->plainAgent($batches, maxPerPass: 100)->run()['analysed']);
        self::assertSame(1000, FraudAgent::MAX_PER_PASS);
    }

    public function testAPassReadsNoFurtherThanTheBoundOfAThousandAccounts(): void
    {
        for ($i = 1; $i <= 1020; ++$i) {
            $this->created('evt-' . $i, (string) $i);
        }
        $batches = [];

        self::assertSame(1000, $this->plainAgent($batches)->run()['analysed']);
        self::assertCount(20, $batches);
        self::assertSame(20, $this->plainAgent($batches)->run()['analysed']);
    }

    /**
     * An agent whose every account has an ordinary address: the model is never asked. Counts the accounts it reads
     * from the shop at once.
     *
     * @param list<int> $batches
     */
    private function plainAgent(array &$batches, int $maxPerPass = FraudAgent::MAX_PER_PASS): FraudAgent
    {
        $client = $this->createStub(AdminApiClientInterface::class);
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use (&$batches): array {
            $batches[] = \count($paths);

            return array_map(static fn (): array => ['ok' => true, 'body' => ['email' => 'jean.dupont@example.com', 'firstName' => 'Jean', 'lastName' => 'Dupont']], $paths);
        });
        $judge = $this->createStub(RiskJudgeInterface::class);
        $judge->method('judge')->willThrowException(new JudgementFailedException('never asked'));

        return new FraudAgent(
            $this->events,
            $this->reports,
            $client,
            new EmailRiskRules(new class() implements DomainCheckerInterface {
                public function acceptsMail(string $domain): ?bool
                {
                    return true;
                }
            }, []),
            $judge,
            new AgentAlerter($this->createStub(NotifierInterface::class), new MockClock(), []),
            new MockClock(),
            maxPerPass: $maxPerPass,
        );
    }

    private function created(string $eventId, string $customerId): void
    {
        $this->events->record(new ShopEvent($eventId, FraudAgent::EVENT, $customerId, ['id' => $customerId], new \DateTimeImmutable()));
    }

    private function agent(NotifierInterface $notifier, bool $judgeFails = false): FraudAgent
    {
        $client = $this->createStub(AdminApiClientInterface::class);
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_intersect_key([
            '1' => ['ok' => true, 'body' => ['email' => 'jean.dupont@example.com', 'firstName' => 'Jean', 'lastName' => 'Dupont']],
            '2' => ['ok' => true, 'body' => ['email' => 'jean@mailinator.com', 'firstName' => 'Jean', 'lastName' => 'Dupont']],
            '3' => ['ok' => true, 'body' => ['email' => 'x7k2qz9p4m@example.com', 'firstName' => null, 'lastName' => null]],
            '404' => ['ok' => false, 'status' => 404, 'error' => 'Not Found'],
        ], $paths));

        $dns = new class() implements DomainCheckerInterface {
            public function acceptsMail(string $domain): ?bool
            {
                return true;
            }
        };
        $judged = &$this->judged;
        $judge = new class($judged, $judgeFails) implements RiskJudgeInterface {
            /**
             * @param list<string> $judged
             */
            public function __construct(private array &$judged, private readonly bool $fails)
            {
            }

            public function judge(RiskAssessment $assessment, bool $hasName): Judgement
            {
                $this->judged[] = $assessment->shape;
                if ($this->fails) {
                    throw new JudgementFailedException('down');
                }

                return new Judgement(Level::Medium, 'The judge says medium.');
            }
        };

        return new FraudAgent(
            $this->events,
            $this->reports,
            $client,
            new EmailRiskRules($dns, ['mailinator.com']),
            $judge,
            new AgentAlerter($notifier, new MockClock(), ['ops@example.com']),
            new MockClock(),
        );
    }
}
