<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Integration;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Store\AgentReportStoreInterface;
use Emagma\McpAgentsBundle\Store\DoctrineAgentReportStore;
use PHPUnit\Framework\Attributes\CoversClass;

/** The journal on a real database: one report per agent and source, read back by level, date and agent. */
#[CoversClass(DoctrineAgentReportStore::class)]
#[CoversClass(AgentReport::class)]
final class DoctrineAgentReportStoreTest extends DatabaseTestCase
{
    public function testAnalysisIsRecordedOncePerAgentAndSource(): void
    {
        $store = $this->store();

        self::assertTrue($store->record($this->report('fraud', 'evt-1', 5, Level::High)));
        self::assertFalse($store->record($this->report('fraud', 'evt-1', 9, Level::Low)), 'the same source, the same agent');
        self::assertTrue($store->record($this->report('stock', 'evt-1', 6, Level::Low)), 'another agent');

        self::assertSame(5, $store->lastSequence('fraud'));
        self::assertSame(6, $store->lastSequence('stock'));
        self::assertSame(0, $store->lastSequence('never-ran'));
    }

    public function testReportsAreReadMostRecentFirstByAgentLevelAndDate(): void
    {
        $store = $this->store();
        $store->record($this->report('fraud', 'evt-1', 1, Level::Low, '2026-11-14 09:00'));
        $store->record($this->report('fraud', 'evt-2', 2, Level::High, '2026-11-14 10:00'));
        $store->record($this->report('stock', 'evt-3', 3, Level::Medium, '2026-11-14 11:00'));

        self::assertSame(['evt-3', 'evt-2', 'evt-1'], $this->sources($store->find()));
        self::assertSame(['evt-2', 'evt-1'], $this->sources($store->find(agent: 'fraud')));
        self::assertSame(['evt-3', 'evt-2'], $this->sources($store->find(atLeast: Level::Medium)));
        self::assertSame(['evt-3'], $this->sources($store->find(since: new \DateTimeImmutable('2026-11-14 10:30'))));
        self::assertSame(['evt-3'], $this->sources($store->find(limit: 1)));
    }

    public function testTheNotificationIsKeptOnceSaved(): void
    {
        $store = $this->store();
        $report = $this->report('fraud', 'evt-1', 1, Level::High);
        $store->record($report);

        $report->markNotified(new \DateTimeImmutable('2026-11-14 12:00'));
        $store->save();
        $this->em->clear();

        $found = $store->find();
        self::assertEquals(new \DateTimeImmutable('2026-11-14 12:00'), $found[0]->getNotifiedAt());
        self::assertSame(['The signal.'], $found[0]->getSignals());
    }

    public function testTheOldReportsArePurged(): void
    {
        $store = $this->store();
        $store->record($this->report('fraud', 'evt-1', 1, Level::Low, '2026-01-01 00:00'));
        $store->record($this->report('fraud', 'evt-2', 2, Level::Low, '2026-11-01 00:00'));

        self::assertSame(1, $store->purgeBefore(new \DateTimeImmutable('2026-06-01')));
        self::assertSame(['evt-2'], $this->sources($store->find(atLeast: Level::Low)));
    }

    private function store(): AgentReportStoreInterface
    {
        return $this->service(AgentReportStoreInterface::class, 'test.report_store');
    }

    private function report(string $agent, string $source, int $sequence, Level $level, string $at = '2026-11-14 09:00'): AgentReport
    {
        return AgentReport::of($agent, $source, $sequence, 'customer', '42', $level, ['The signal.'], 'Explained.', new \DateTimeImmutable($at));
    }

    /**
     * @param list<AgentReport> $reports
     *
     * @return list<string>
     */
    private function sources(array $reports): array
    {
        return array_map(static fn (AgentReport $report): string => $report->getSource(), $reports);
    }
}
