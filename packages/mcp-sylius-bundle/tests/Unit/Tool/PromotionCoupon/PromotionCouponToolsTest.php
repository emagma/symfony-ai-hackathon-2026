<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\PromotionCoupon;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\PromotionCoupon\GeneratePromotionCoupons;
use Emagma\McpSyliusBundle\Tool\PromotionCoupon\SearchPromotionCoupons;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchPromotionCoupons::class)]
#[CoversClass(GeneratePromotionCoupons::class)]
#[CoversClass(PromotionCouponShaper::class)]
final class PromotionCouponToolsTest extends McpToolTestCase
{
    private const string PROMOTION = 'christmas';

    private const string COUPON_PATH = 'promotions/christmas/coupons/XMAS10';

    private const string PROMOTION_PATH = 'promotions/christmas';

    public function testListQueriesTheNestedCollection(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('promotions/christmas/coupons', [
                'page' => 1,
                'itemsPerPage' => 30,
                'code' => 'XMAS10',
                'order' => ['used' => 'desc'],
            ])
            ->willReturn($this->collection([$this->coupon()], total: 1));

        $this->call(new SearchPromotionCoupons($client), self::PROMOTION, code: 'XMAS10', sort: 'used');
    }

    public function testListIgnoresASortFieldTheApiCannotOrderBy(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('promotions/christmas/coupons', self::callback(static function (array $query): bool {
                self::assertArrayNotHasKey('order', $query);

                return true;
            }))
            ->willReturn($this->collection([$this->coupon()], total: 1));

        $this->call(new SearchPromotionCoupons($client), self::PROMOTION, sort: 'createdAt');
    }

    /** The nested collection answers 200 with an empty list for a promotion that does not exist. */
    public function testListTellsAnUnknownPromotionApartFromAnEmptyOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            function (\Stringable|string $path) {
                $path = (string) $path;
                if (self::PROMOTION_PATH === (string) $path) {
                    throw $this->clientException(404);
                }

                return $this->collection([], total: 0);
            },
        );

        $result = $this->call(new SearchPromotionCoupons($client), self::PROMOTION);

        self::assertFalse($result['found']);
        self::assertSame([], $result['items']);
    }

    public function testListReportsAPromotionThatSimplyHasNoCouponsAsFound(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            fn (\Stringable|string $path): array => self::PROMOTION_PATH === (string) $path
                ? $this->promotion()
                : $this->collection([], total: 0),
        );

        self::assertTrue($this->call(new SearchPromotionCoupons($client), self::PROMOTION)['found']);
    }

    public function testListSkipsTheExtraProbeWhenCouponsCameBack(): void
    {
        $client = $this->apiClient();
        // One call, not two: the promotion obviously exists if it has coupons.
        $client->expects(self::once())
            ->method('getDecoded')
            ->willReturn($this->collection([$this->coupon()], total: 1));

        self::assertTrue($this->call(new SearchPromotionCoupons($client), self::PROMOTION)['found']);
    }

    public function testGenerateRefusesABatchLargerThanTheCap(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $result = $this->call(new GeneratePromotionCoupons($client, $this->audit()), self::PROMOTION, 10001, 8, confirm: true);

        self::assertSame('amount_out_of_range', $result['reason']);
    }

    public function testGenerateRefusesACodeLengthOutsideTheVendorRange(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        self::assertSame(
            'code_length_out_of_range',
            $this->call(new GeneratePromotionCoupons($client, $this->audit()), self::PROMOTION, 10, 41, confirm: true)['reason'],
        );
    }

    public function testGenerateRefusesAPromotionThatIsNotCouponBased(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['couponBased' => false] + $this->promotion());
        $client->expects(self::never())->method('post');

        self::assertSame(
            'promotion_not_coupon_based',
            $this->call(new GeneratePromotionCoupons($client, $this->audit()), self::PROMOTION, 10, 8, confirm: true)['reason'],
        );
    }

    /** The generate operation answers a bare JSON array: the one admin endpoint without a Hydra envelope. */
    public function testGenerateReadsASampleOutOfTheUnwrappedResponse(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            fn (\Stringable|string $path): array => self::PROMOTION_PATH === (string) $path
                ? $this->promotion()
                : $this->collection([], total: 4),
        );
        $client->expects(self::once())
            ->method('post')
            ->with('promotions/christmas/coupons/generate', [
                'amount' => 7,
                'codeLength' => 8,
                'prefix' => 'XMAS-',
                'usageLimit' => 1,
            ], [])
            ->willReturn([
                ['code' => 'XMAS-AAAAAAAA'],
                ['code' => 'XMAS-BBBBBBBB'],
                ['code' => 'XMAS-CCCCCCCC'],
                ['code' => 'XMAS-DDDDDDDD'],
                ['code' => 'XMAS-EEEEEEEE'],
                ['code' => 'XMAS-FFFFFFFF'],
                ['code' => 'XMAS-GGGGGGGG'],
            ]);

        $result = $this->call(
            new GeneratePromotionCoupons($client, $this->audit()),
            self::PROMOTION,
            7,
            8,
            prefix: 'XMAS-',
            usageLimit: 1,
            confirm: true,
        );

        self::assertTrue($result['generated']);
        self::assertSame(7, $result['amount']);
        // A sample, not the batch: ten thousand codes would flood the caller.
        self::assertCount(5, $result['sample']);
        self::assertSame('XMAS-AAAAAAAA', $result['sample'][0]);
    }

    public function testGenerateSurfacesTheGeneratorFailureMessage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            fn (\Stringable|string $path): array => self::PROMOTION_PATH === (string) $path
                ? $this->promotion()
                : $this->collection([], total: 0),
        );
        // How Sylius reports a code space too small: a 422 with a plain `detail` and no violations.
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(422);
        $response->method('getInfo')->willReturnCallback(
            static fn (?string $type = null) => match ($type) {
                'http_code' => 422,
                'url' => 'https://test/',
                'response_headers' => ['HTTP/1.1 422'],
                default => null,
            },
        );
        $response->method('toArray')->willReturn([
            'detail' => 'It is not possible to generate 500 unique coupons with 1 code length',
        ]);
        $client->method('post')->willThrowException(
            new \Symfony\Component\HttpClient\Exception\ClientException($response),
        );

        $result = $this->call(new GeneratePromotionCoupons($client, $this->audit()), self::PROMOTION, 500, 1, confirm: true);

        self::assertSame('validation_error', $result['reason']);
        self::assertStringContainsString('500 unique coupons', $result['errors'][0]['message']);
    }

    public function testElicitationDeclinedGeneratesNothing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            fn (\Stringable|string $path): array => self::PROMOTION_PATH === (string) $path
                ? $this->promotion()
                : $this->collection([], total: 0),
        );
        $client->expects(self::never())->method('post');

        $tool = new GeneratePromotionCoupons($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(self::PROMOTION, 5000, 8, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['generated']);
    }

    /**
     * @return array<string, mixed>
     */
    private function promotion(): array
    {
        return [
            '@id' => '/api/v2/admin/promotions/christmas',
            'code' => 'christmas',
            'name' => 'Christmas',
            'couponBased' => true,
            'coupons' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coupon(): array
    {
        return [
            '@id' => '/api/v2/admin/promotions/christmas/coupons/XMAS10',
            'code' => 'XMAS10',
            'promotion' => '/api/v2/admin/promotions/christmas',
            'usageLimit' => 100,
            'used' => 7,
            'perCustomerUsageLimit' => 1,
            'reusableFromCancelledOrders' => true,
            'expiresAt' => null,
            'createdAt' => '2026-09-01 10:00:00',
        ];
    }
}
