<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Zone;

use Emagma\McpSyliusBundle\Output\Zone\ZoneOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZoneOutput::class)]
final class ZoneOutputTest extends TestCase
{
    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'code' => 'EU',
            'name' => 'European Union',
            'type' => 'country',
            'scope' => 'all',
            'priority' => 5,
            'members' => [
                ['code' => 'FR'],
                ['code' => 'DE'],
            ],
        ];

        $shaped = ZoneOutput::fromPayload($raw, 'EU');

        self::assertTrue($shaped->found);
        self::assertSame('EU', $shaped->code);
        self::assertSame('European Union', $shaped->name);
        self::assertSame('country', $shaped->type);
        self::assertSame('all', $shaped->scope);
        self::assertSame(5, $shaped->priority);
        self::assertSame(['FR', 'DE'], $shaped->memberCodes);
        self::assertSame(2, $shaped->membersCount);
    }

    public function testItHandlesMembersAsIriStrings(): void
    {
        $raw = [
            'code' => 'EU',
            'members' => [
                '/api/v2/admin/countries/FR',
                '/api/v2/admin/countries/DE',
            ],
        ];

        $shaped = ZoneOutput::fromPayload($raw, 'EU');

        self::assertSame(['FR', 'DE'], $shaped->memberCodes);
        self::assertSame(2, $shaped->membersCount);
    }

    public function testItFallsBackToFallbackCodeWhenCodeMissing(): void
    {
        $shaped = ZoneOutput::fromPayload([], 'FALLBACK');

        self::assertSame('FALLBACK', $shaped->code);
    }

    public function testItReturnsNullFieldsWhenRawIsMissing(): void
    {
        $shaped = ZoneOutput::fromPayload(['code' => 'X'], 'X');

        self::assertNull($shaped->name);
        self::assertNull($shaped->type);
        self::assertNull($shaped->scope);
        self::assertNull($shaped->priority);
        self::assertSame([], $shaped->memberCodes);
        self::assertSame(0, $shaped->membersCount);
    }
}
