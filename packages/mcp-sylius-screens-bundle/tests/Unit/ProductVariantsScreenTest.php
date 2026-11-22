<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Tests\Unit;

use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Channel\GetChannels;
use Emagma\McpSyliusBundle\Tool\ProductVariant\GetProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\SearchProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\UpsertProductVariants;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Emagma\McpSyliusScreensBundle\Screen\ProductVariantsScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ProductVariantsScreen::class)]
final class ProductVariantsScreenTest extends McpToolTestCase
{
    private const array CAP_S = [
        'code' => 'CAP-S',
        'product' => '/api/v2/admin/products/CAP',
        'tracked' => true,
        'onHand' => 4,
        'onHold' => 1,
        'translations' => ['en_US' => ['name' => 'Cap S']],
        'channelPricings' => [
            'WEB_US' => ['channelCode' => 'WEB_US', 'price' => 9905],
            'WEB_EUR' => ['channelCode' => 'WEB_EUR', 'price' => 8900],
        ],
    ];

    private const array CAP_M = [
        'code' => 'CAP-M',
        'product' => '/api/v2/admin/products/CAP',
        'tracked' => false,
        'translations' => ['en_US' => ['name' => 'Cap M']],
        'channelPricings' => ['WEB_US' => ['channelCode' => 'WEB_US', 'price' => 10000]],
    ];

    public function testTheVariantsOfAProductWithTheirPriceInOneChannelAndTheirStock(): void
    {
        $data = $this->screen($this->shop())->load(['productCode' => 'CAP', 'channelCode' => 'WEB_EUR']);

        self::assertSame('WEB_EUR', $data['channelCode']);
        self::assertSame(['WEB_US', 'WEB_EUR'], $data['channelCodes']);
        self::assertSame('EUR', $data['currency']);
        self::assertSame(
            ['code' => 'CAP-S', 'name' => 'Cap S', 'price' => '89.00', 'tracked' => true, 'onHand' => 4, 'onHold' => 1],
            $data['variants'][0],
        );
        self::assertNull($data['variants'][1]['price'], 'no price in this channel');
    }

    public function testWithoutAChannelTheFirstOneAndAnUnknownProductIsSaid(): void
    {
        self::assertSame('WEB_US', $this->screen($this->shop())->load(['productCode' => 'CAP'])['channelCode']);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([]));
        $this->expectException(ScreenException::class);
        $this->expectExceptionMessage('does not exist or has no variant');
        $this->screen($client)->load(['productCode' => 'NOPE']);
    }

    public function testWhatIsTypedIsCheckedBeforeAnythingIsWritten(): void
    {
        $client = $this->shop();
        $client->expects(self::never())->method('putMany');

        $result = $this->screen($client)->submit(['productCode' => 'CAP'], ['price_0' => '12.345', 'stock_0' => '-1', 'price_1' => '100'], null);

        self::assertFalse($result->saved);
        self::assertSame(['price_0' => 'Not an amount in USD.', 'stock_0' => 'A whole number, zero or more.'], $result->errors);
    }

    public function testTheSameValuesAreNotAWriteAndAnUntrackedStockIsIgnored(): void
    {
        $client = $this->shop();
        $client->expects(self::never())->method('putMany');

        $result = $this->screen($client)->submit(['productCode' => 'CAP'], ['price_0' => '99,05', 'stock_0' => '4', 'price_1' => '100.00', 'stock_1' => '12'], null);

        self::assertFalse($result->saved);
        self::assertSame('Nothing changed: nothing was saved.', $result->message);
    }

    public function testAChangeGoesThroughTheWriteConfirmationOfTheTool(): void
    {
        $client = $this->shop();
        $client->expects(self::never())->method('putMany');

        // No request context: nobody can be asked, so the tool previews and writes nothing.
        $result = $this->screen($client)->submit(['productCode' => 'CAP'], ['price_0' => '95', 'stock_0' => '6'], null);

        self::assertFalse($result->saved);
        self::assertSame('This client cannot ask you to confirm a write: nothing was saved.', $result->message);
    }

    /**
     * @return AdminApiClientInterface&MockObject
     */
    private function shop(): AdminApiClientInterface
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([self::CAP_S, self::CAP_M]));
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths): array {
            $bodies = [
                'CAP-S' => self::CAP_S,
                'CAP-M' => self::CAP_M,
                'WEB_US' => ['code' => 'WEB_US', 'baseCurrency' => '/api/v2/admin/currencies/USD'],
                'WEB_EUR' => ['code' => 'WEB_EUR', 'baseCurrency' => '/api/v2/admin/currencies/EUR'],
            ];
            $results = [];
            foreach (array_keys($paths) as $key) {
                $results[$key] = isset($bodies[$key]) ? ['ok' => true, 'status' => 200, 'body' => $bodies[$key]] : ['ok' => false, 'status' => 404];
            }

            return $results;
        });

        return $client;
    }

    private function screen(AdminApiClientInterface $client): ProductVariantsScreen
    {
        return new ProductVariantsScreen(
            new SearchProductVariants($client, 'en_US', $this->adapter()),
            new GetProductVariants($client, 'en_US'),
            new GetChannels($client),
            new UpsertProductVariants($client, new ReferenceResolver($client), $this->audit(), 'en_US'),
        );
    }
}
