<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\PromotionCoupon;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\PromotionCoupon\DeletePromotionCoupons;
use Emagma\McpSyliusBundle\Tool\PromotionCoupon\GetPromotionCoupons;
use Emagma\McpSyliusBundle\Tool\PromotionCoupon\UpsertPromotionCoupons;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DeletePromotionCoupons::class)]
#[CoversClass(GetPromotionCoupons::class)]
#[CoversClass(UpsertPromotionCoupons::class)]
final class PromotionCouponBatchToolsTest extends McpToolTestCase
{
    public function testTheUpsertChecksThePromotionOnceThenWritesUnderIt(): void
    {
        $welcome = ['code' => 'WELCOME10', 'usageLimit' => 100, 'perCustomerUsageLimit' => 1, 'reusableFromCancelledOrders' => true, 'expiresAt' => null];
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecoded')->with('promotions/XMAS')->willReturn(['code' => 'XMAS', 'couponBased' => true]);
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => str_ends_with((string) $path, '/WELCOME10') ? ['ok' => true, 'body' => $welcome] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            $paths,
        ));
        $client->expects(self::once())->method('postMany')
            ->with(['SANTA' => ['path' => 'promotions/XMAS/coupons', 'body' => ['code' => 'SANTA', 'reusableFromCancelledOrders' => true, 'usageLimit' => 10]]])
            ->willReturn(['SANTA' => ['ok' => true, 'body' => ['code' => 'SANTA']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['WELCOME10' => ['path' => 'promotions/XMAS/coupons/WELCOME10', 'body' => array_replace($welcome, ['usageLimit' => null, 'expiresAt' => '2026-12-31T23:59:59+00:00'])]])
            ->willReturn(['WELCOME10' => ['ok' => true, 'body' => $welcome]]);

        $result = $this->call(new UpsertPromotionCoupons($client, $this->audit()), promotionCode: 'XMAS', items: [
            ['code' => 'WELCOME10', 'usageLimit' => null, 'expiresAt' => '2026-12-31T23:59:59+00:00', 'perCustomerUsageLimit' => 1],
            ['code' => 'SANTA', 'usageLimit' => 10],
        ], confirm: true);

        self::assertSame(['usageLimit' => ['before' => 100, 'after' => null], 'expiresAt' => ['before' => null, 'after' => '2026-12-31T23:59:59+00:00']], $result['results']['WELCOME10']['diff']);
        self::assertSame('created', $result['results']['SANTA']['action']);
    }

    public function testAnAutomaticOrUnknownPromotionRefusesTheWholeCall(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(static function (\Stringable|string $path): array {
            $path = (string) $path;
            if ('promotions/AUTO' === (string) $path) {
                return ['code' => 'AUTO', 'couponBased' => false];
            }
            $http = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
            $response = $http->request('GET', 'https://shop.test/' . $path);

            throw new ClientException($response);
        });
        $client->expects(self::never())->method('getDecodedMany');
        $tool = new UpsertPromotionCoupons($client, $this->audit());

        self::assertSame('promotion_not_coupon_based', $this->call($tool, promotionCode: 'AUTO', items: [['code' => 'X']])['reason']);
        self::assertSame('promotion_not_found', $this->call($tool, promotionCode: 'NOPE', items: [['code' => 'X']])['reason']);
    }

    public function testGetAndDeleteAddressTheCouponsUnderThePromotion(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => 'promotions/XMAS/coupons/A' === (string) $path ? ['ok' => true, 'body' => ['code' => 'A', 'used' => 3]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            $paths,
        ));
        $client->expects(self::once())->method('deleteMany')->with(['A' => 'promotions/XMAS/coupons/A'])->willReturn(['A' => ['ok' => true]]);

        $got = $this->call(new GetPromotionCoupons($client), promotionCode: 'XMAS', codes: ['A', 'B']);
        self::assertSame(['B'], $got['missing']);
        self::assertSame('A', $got['found'][0]['code']);
        self::assertSame('XMAS', $got['found'][0]['promotionCode']);

        $deleted = $this->call(new DeletePromotionCoupons($client, $this->audit()), promotionCode: 'XMAS', codes: ['A', 'B'], confirm: true);
        self::assertSame('deleted', $deleted['results']['A']['action']);
        self::assertSame('not_found', $deleted['results']['B']['reason']);
    }
}
