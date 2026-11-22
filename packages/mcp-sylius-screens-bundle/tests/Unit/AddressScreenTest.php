<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Tests\Unit;

use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Address\UpdateAddress;
use Emagma\McpSyliusScreensBundle\Screen\AddressScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(AddressScreen::class)]
final class AddressScreenTest extends McpToolTestCase
{
    private const array SHIPPING = [
        '@id' => '/api/v2/admin/addresses/79',
        'firstName' => 'Emma',
        'lastName' => 'Laurent',
        'street' => '12 Market Street',
        'postcode' => '94105',
        'city' => 'San Francisco',
        'countryCode' => 'US',
    ];

    public function testTheShippingAddressOfAnOrderByDefaultItsBillingOneWhenAsked(): void
    {
        $screen = $this->screen($this->shop());

        $shipping = $screen->load(['orderToken' => 'tok']);
        self::assertSame('Shipping address of order #000000025', $shipping['subject']);
        self::assertSame('79', $shipping['addressId']);
        self::assertSame(['label' => 'Street', 'value' => '12 Market Street', 'required' => true], $shipping['fields']['street']);
        self::assertSame('', $shipping['fields']['company']['value']);

        $billing = $screen->load(['orderToken' => 'tok', 'kind' => 'billing']);
        self::assertSame('Billing address of order #000000025', $billing['subject']);
        self::assertSame('80', $billing['addressId']);
    }

    public function testTheDefaultAddressOfACustomerOrThatThereIsNone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnOnConsecutiveCalls(
            ['defaultAddress' => ['id' => 12] + self::SHIPPING],
            ['defaultAddress' => null],
        );
        $screen = $this->screen($client);

        $data = $screen->load(['customerId' => 5]);
        self::assertSame('Default address of customer #5', $data['subject']);
        self::assertSame('12', $data['addressId']);

        $this->expectException(ScreenException::class);
        $this->expectExceptionMessage('This customer has no default address.');
        $screen->load(['customerId' => 6]);
    }

    public function testTheShippingAddressOfAShippedOrderIsShownButNoLongerChanged(): void
    {
        $client = $this->shop('shipped');
        $client->expects(self::never())->method('put');
        $screen = $this->screen($client);

        self::assertStringContainsString('already shipped', (string) $screen->load(['orderToken' => 'tok'])['locked']);
        self::assertNull($screen->load(['orderToken' => 'tok', 'kind' => 'billing'])['locked'], 'an invoice can still be corrected');

        $result = $screen->submit(['orderToken' => 'tok'], ['street' => '14 Market Street'], null);
        self::assertFalse($result->saved);
        self::assertStringContainsString('already shipped', $result->message);
    }

    public function testWithoutAnythingToShowTheScreenSaysWhatItNeeds(): void
    {
        $this->expectException(ScreenException::class);
        $this->expectExceptionMessage('params.orderToken');
        $this->screen($this->apiClient())->load([]);
    }

    public function testWhatIsTypedIsCheckedBeforeAnythingIsWritten(): void
    {
        $client = $this->shop();
        $client->expects(self::never())->method('put');

        $result = $this->screen($client)->submit(['orderToken' => 'tok'], ['street' => ' ', 'countryCode' => 'us', 'city' => 'Oakland'], null);

        self::assertFalse($result->saved);
        self::assertSame(['street' => 'Required.', 'countryCode' => 'Two capital letters, e.g. FR.'], $result->errors);
    }

    public function testTheSameAddressIsNotAWriteAndAChangeGoesThroughTheConfirmationOfTheTool(): void
    {
        $client = $this->shop();
        $client->expects(self::never())->method('put');
        $screen = $this->screen($client);

        self::assertSame('Nothing changed: nothing was saved.', $screen->submit(['orderToken' => 'tok'], ['street' => '12 Market Street', 'company' => ''], null)->message);

        // No request context: nobody can be asked, so update_address previews and writes nothing.
        self::assertSame(
            'This client cannot ask you to confirm a write: nothing was saved.',
            $screen->submit(['orderToken' => 'tok'], ['street' => '14 Market Street'], null)->message,
        );
    }

    /**
     * An order with its two addresses, and address #79 as update_address reads it.
     *
     * @return AdminApiClientInterface&MockObject
     */
    private function shop(string $shippingState = 'ready'): AdminApiClientInterface
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(static fn (mixed $path): array => $path instanceof Route && Resource::Address === $path->resource
            ? self::SHIPPING
            : [
                'number' => '000000025',
                'shippingState' => $shippingState,
                'shippingAddress' => self::SHIPPING,
                'billingAddress' => ['@id' => '/api/v2/admin/addresses/80'] + self::SHIPPING,
            ]);

        return $client;
    }

    private function screen(AdminApiClientInterface $client): AddressScreen
    {
        return new AddressScreen($client, new UpdateAddress($client, $this->audit()));
    }
}
