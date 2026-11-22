<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Tests\Unit;

use Emagma\McpAgentsBundle\Entity\AgentReport;
use Emagma\McpAgentsBundle\Level;
use Emagma\McpAgentsBundle\Testing\InMemoryAgentReportStore;
use Emagma\McpAgentsBundle\Tool\ListAgentReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListAgentReports::class)]
#[CoversClass(InMemoryAgentReportStore::class)]
final class ListAgentReportsTest extends TestCase
{
    public function testTheAnalysesThatFoundSomethingAreListedTheMostRecentFirst(): void
    {
        $store = new InMemoryAgentReportStore();
        $store->record(AgentReport::of('fraud', 'evt-1', 1, 'customer', '41', Level::Low, [], 'Nothing unusual.', new \DateTimeImmutable('2026-11-14 09:00')));
        $store->record(AgentReport::of('fraud', 'evt-2', 2, 'customer', '42', Level::High, ['Disposable domain.'], 'Looks disposable.', new \DateTimeImmutable('2026-11-14 10:00')));
        $store->record(AgentReport::of('stock', 'evt-3', 3, 'variant', 'TEE-M', Level::Medium, ['Out of stock.'], 'Sold yesterday.', new \DateTimeImmutable('2026-11-14 11:00')));
        $tool = new ListAgentReports($store);

        $all = $tool();
        self::assertSame(['stock', 'fraud'], array_column($all['items'], 'agent'), 'low ones are the analyses that found nothing');
        self::assertSame(['type' => 'customer', 'id' => '42'], $all['items'][1]['subject']);
        self::assertFalse($all['items'][1]['alerted']);

        self::assertSame(1, $tool(agent: 'fraud')['total']);
        self::assertSame(2, $tool(agent: 'fraud', level: 'low')['total']);
        self::assertSame(1, $tool(since: '2026-11-14T10:30:00+00:00')['total']);
        self::assertSame('invalid_since', $tool(since: 'yesterday-ish')['reason'] ?? null);
    }

    public function testAnAnalysisOfTheSameSourceByTheSameAgentIsRecordedOnce(): void
    {
        $store = new InMemoryAgentReportStore();
        $first = AgentReport::of('fraud', 'evt-1', 7, 'customer', '41', Level::Low, [], 'Nothing unusual.', new \DateTimeImmutable());

        self::assertTrue($store->record($first));
        self::assertFalse($store->record(AgentReport::of('fraud', 'evt-1', 7, 'customer', '41', Level::High, [], 'Again.', new \DateTimeImmutable())));
        self::assertTrue($store->record(AgentReport::of('stock', 'evt-1', 7, 'customer', '41', Level::Low, [], 'Another agent.', new \DateTimeImmutable())));
        self::assertSame(7, $store->lastSequence('fraud'));
        self::assertSame(0, $store->lastSequence('never-ran'));
    }
}
