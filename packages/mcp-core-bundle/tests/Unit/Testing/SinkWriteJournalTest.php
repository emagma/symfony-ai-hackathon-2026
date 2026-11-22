<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Testing;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpCoreBundle\Testing\SinkWriteJournal;
use Emagma\McpCoreBundle\Testing\WriteSinkInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SinkWriteJournal::class)]
final class SinkWriteJournalTest extends TestCase
{
    public function testAWriteReachesTheSink(): void
    {
        $sink = $this->createMock(WriteSinkInterface::class);
        $sink->expects(self::once())->method('persist')->with(self::callback(static fn (RecordedWrite $write): bool => '12' === $write->getTargetCode()));

        (new SinkWriteJournal($sink))->record('upsert_customers', 'customer', '12', [], [], []);
    }

    public function testAnEmailAsTargetCodeFailsTheTest(): void
    {
        $sink = $this->createMock(WriteSinkInterface::class);
        $sink->expects(self::never())->method('persist');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('record the id instead');

        (new SinkWriteJournal($sink))->stage('upsert_customers', 'customer', 'jane@example.com', [], [], []);
    }
}
