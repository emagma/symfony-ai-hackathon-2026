<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shared;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Address\GetAddresses;
use Emagma\McpSyliusBundle\Tool\Payment\GetPayments;
use Emagma\McpSyliusBundle\Tool\ProductReview\GetProductReviews;
use Emagma\McpSyliusBundle\Tool\Shipment\GetShipments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(GetAddresses::class)]
#[CoversClass(GetPayments::class)]
#[CoversClass(GetProductReviews::class)]
#[CoversClass(GetShipments::class)]
final class BatchGetByIdToolsTest extends McpToolTestCase
{
    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function tools(): iterable
    {
        yield 'payments' => [GetPayments::class, 'payments', 'state'];
        yield 'shipments' => [GetShipments::class, 'shipments', 'state'];
        yield 'addresses' => [GetAddresses::class, 'addresses', 'city'];
        yield 'product reviews' => [GetProductReviews::class, 'product-reviews', 'title'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('tools')]
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(string $class, string $collection, string $field): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['7' => $collection . '/7', '9' => $collection . '/9'])
            ->willReturn([
                '7' => ['ok' => true, 'body' => ['id' => 7, $field => 'x', 'amount' => 1, 'rating' => 5]],
                '9' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call($this->tool($class, $client), ids: ['7', '9', '7', ' ']);

        self::assertCount(1, $result['found']);
        self::assertSame('x', $result['found'][0][$field]);
        self::assertSame(['9'], $result['missing']);
        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_ids'], $this->call($this->tool($class, $this->apiClient()), ids: array_map(strval(...), range(1, 51))));
    }

    /**
     * @param class-string $class
     */
    private function tool(string $class, AdminApiClientInterface $client): object
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        return null !== $constructor && $constructor->getNumberOfParameters() > 1
            ? new $class($client, $this->grants(), $this->audit())
            : new $class($client);
    }
}
