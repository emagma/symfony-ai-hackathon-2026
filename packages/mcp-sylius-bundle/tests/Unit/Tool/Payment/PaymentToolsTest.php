<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Payment;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Payment\SearchPayments;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchPayments::class)]
#[CoversClass(PaymentShaper::class)]
final class PaymentToolsTest extends McpToolTestCase
{
    public function testItSendsOnlyPaginationByDefault(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('payments', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchPayments($client));
    }

    public function testItMapsTheFiltersToTheVendorNames(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('payments', [
                'state' => 'completed',
                'order.channel.code' => 'FASHION_WEB',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchPayments($client), state: 'completed', channel: 'FASHION_WEB');
    }

    public function testItProjectsPaymentsAndDropsTheGatewayDetails(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([[
            'id' => 9,
            'state' => 'completed',
            'amount' => 12500,
            'currencyCode' => 'EUR',
            'method' => '/api/v2/admin/payment-methods/stripe',
            'order' => '/api/v2/admin/orders/TOKEN123',
            'createdAt' => '2026-01-15T10:00:00+00:00',
            'updatedAt' => '2026-01-15T10:05:00+00:00',
            'details' => ['payment_intent' => 'pi_secret_123'],
        ]], total: 1));

        $result = $this->call(new SearchPayments($client));

        self::assertSame([[
            'id' => '9',
            'state' => 'completed',
            'amount' => 12500,
            'currencyCode' => 'EUR',
            'methodCode' => 'stripe',
            'createdAt' => '2026-01-15T10:00:00+00:00',
            'updatedAt' => '2026-01-15T10:05:00+00:00',
            'orderTokenValue' => 'TOKEN123',
        ]], $result['items']);
        self::assertStringNotContainsString('pi_secret_123', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testItFallsBackToTheIriTailWhenTheIdIsAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            ['@id' => '/api/v2/admin/payments/77', 'state' => 'new'],
        ], total: 1));

        self::assertSame('77', $this->call(new SearchPayments($client))['items'][0]['id']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('payments', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchPayments($client), itemsPerPage: 999);
    }

    public function testHasMoreReflectsTheReportedTotal(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 30, ['id' => 1]),
            total: 87,
        ));

        self::assertTrue($this->call(new SearchPayments($client))['hasMore']);
    }
}
