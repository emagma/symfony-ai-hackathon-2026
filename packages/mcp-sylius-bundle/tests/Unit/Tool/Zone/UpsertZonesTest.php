<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Zone;

use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Zone\UpsertZones;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertZones::class)]
final class UpsertZonesTest extends McpToolTestCase
{
    public function testACreationSendsTheBodyTheSingleToolSent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['EU' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']]);
        $client->expects(self::once())->method('postMany')
            ->with(['EU' => ['path' => 'zones', 'body' => [
                'code' => 'EU', 'name' => 'European Union', 'type' => 'country', 'scope' => 'all', 'priority' => 3,
                'members' => [['code' => 'FR'], ['code' => 'DE']],
            ]]])
            ->willReturn(['EU' => ['ok' => true, 'body' => ['code' => 'EU']]]);

        $result = $this->call(new UpsertZones($client, $this->audit()), items: [
            ['code' => 'EU', 'name' => 'European Union', 'type' => 'country', 'priority' => 3, 'members' => ['FR', 'DE']],
        ], confirm: true);

        self::assertSame('created', $result['results']['EU']['action']);
        self::assertSame(['before' => null, 'after' => 'all'], $result['results']['EU']['diff']['scope']);
    }

    public function testAnUpdateReplacesTheMembersAndLeavesTypeAndScopeAlone(): void
    {
        $current = ['code' => 'EU', 'name' => 'European Union', 'type' => 'country', 'scope' => 'all', 'priority' => 0, 'members' => [['code' => 'FR'], ['code' => 'DE']]];
        $expected = $current;
        $expected['name'] = 'EU Zone Renamed';
        $expected['members'] = [['code' => 'FR'], ['code' => 'IT']];

        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['EU' => ['ok' => true, 'body' => $current]]);
        $client->expects(self::once())->method('putMany')
            ->with(['EU' => ['path' => 'zones/EU', 'body' => $expected]])
            ->willReturn(['EU' => ['ok' => true, 'body' => $expected]]);

        $result = $this->call(new UpsertZones($client, $this->audit()), items: [
            ['code' => 'EU', 'name' => 'EU Zone Renamed', 'type' => 'province', 'scope' => 'tax', 'members' => ['FR', 'IT']],
        ], confirm: true);

        self::assertSame('updated', $result['results']['EU']['action']);
        self::assertSame(['name', 'members'], array_keys($result['results']['EU']['diff']));
        self::assertSame(['FR', 'DE'], $result['results']['EU']['diff']['members']['before']);
    }

    public function testTheSameMembersInAnotherOrderAreUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['EU' => ['ok' => true, 'body' => ['code' => 'EU', 'name' => 'EU', 'members' => [['code' => 'FR'], ['code' => 'DE']]]]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertZones($client, $this->audit()), items: [['code' => 'EU', 'members' => ['DE', 'FR']]]);

        self::assertSame('unchanged', $result['results']['EU']['action']);
    }

    public function testAnInvalidTypeRefusesTheBatch(): void
    {
        $result = $this->call(new UpsertZones($this->apiClient(), $this->audit()), items: [['code' => 'EU', 'name' => 'x', 'type' => 'planet']], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame('type is one of zone, country, province', $result['errors'][0]['message']);
    }

    public function testAPriorityOnALineWithoutItIsRefusedNotSilentlyDropped(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new UpsertZones($client, $this->audit(), new SyliusVersion('2.1')), items: [['code' => 'EU', 'priority' => 2]]);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame('priority needs Sylius 2.2 or later; this shop runs 2.1', $result['errors'][0]['message']);
    }
}
