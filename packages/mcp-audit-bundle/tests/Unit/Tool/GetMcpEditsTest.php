<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit\Tool;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Tests\AuditTestTrait;
use Emagma\McpAuditBundle\Tool\GetMcpEdits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetMcpEdits::class)]
final class GetMcpEditsTest extends TestCase
{
    use AuditTestTrait;

    public function testAuditEntriesAreFetchedInOneQueryAndTheMissingOnesNamed(): void
    {
        $entry = new AuditEntry(tool: 'upsert_taxons', targetType: 'taxon', targetCode: 'mugs', beforeState: [], afterState: [], diff: [], sessionId: 's');
        (new \ReflectionProperty($entry, 'id'))->setValue($entry, 3);
        $repository = $this->createAuditRepository(paginateItems: [$entry]);

        /** @var array{found: list<array<string, mixed>>, missing: list<int>} $result */
        $result = json_decode(json_encode((new GetMcpEdits($repository, $this->createAuditScope()))(ids: [3, 4, 3]), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(3, $result['found'][0]['id']);
        self::assertSame([4], $result['missing']);
    }
}
