<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shipment;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shipment\SearchShipments;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchShipments::class)]
#[CoversClass(ShipmentShaper::class)]
final class ShipmentToolsTest extends McpToolTestCase
{
    public function testItSendsOnlyPaginationByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipments', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchShipments($client));
    }

    public function testItMapsTheFiltersToTheVendorNames(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipments', [
                'state' => 'ready',
                'method.code' => 'ups',
                'order.channel.code' => 'FASHION_WEB',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchShipments($client), state: 'ready', method: 'ups', channel: 'FASHION_WEB');
    }

    public function testTheListingProjectionCarriesNoUnitCount(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([[
            'id' => 3,
            'state' => 'shipped',
            'method' => '/api/v2/admin/shipping-methods/ups',
            'tracking' => '1Z999',
            'shippedAt' => '2026-01-16T09:00:00+00:00',
            'createdAt' => '2026-01-15T10:00:00+00:00',
            'order' => '/api/v2/admin/orders/TOKEN123',
        ]], total: 1));

        $result = $this->call(new SearchShipments($client));

        // mcp:shipment:lean leaves the unit rows off the wire: a count here would always say 0.
        self::assertSame([[
            'id' => '3',
            'state' => 'shipped',
            'methodCode' => 'ups',
            'tracking' => '1Z999',
            'shippedAt' => '2026-01-16T09:00:00+00:00',
            'createdAt' => '2026-01-15T10:00:00+00:00',
            'orderTokenValue' => 'TOKEN123',
        ]], $result['items']);
        self::assertArrayNotHasKey('unitsCount', $result['items'][0]);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('shipments', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchShipments($client), itemsPerPage: 999);
    }
}
