<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Address;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Address\UpdateAddress;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateAddress::class)]
final class UpdateAddressTest extends McpToolTestCase
{
    private const array CURRENT = [
        '@id' => '/api/v2/admin/addresses/12',
        'firstName' => 'Jean',
        'lastName' => 'Dupont',
        'street' => '21 rue de la Paix',
        'postcode' => '75002',
        'city' => 'Paris',
        'countryCode' => 'FR',
        'phoneNumber' => '0102030405',
    ];

    public function testOnlyTheGivenFieldsChangeTheRestOfTheAddressIsSentBackAsItWas(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(self::CURRENT);
        $client->expects(self::once())->method('put')
            ->with(self::callback(static fn (mixed $path): bool => true), self::callback(static function (array $body): bool {
                self::assertSame('12 rue de la Paix', $body['street']);
                self::assertSame('Dupont', $body['lastName'], 'PUT replaces the address whole: what is not given is kept');
                self::assertSame('0102030405', $body['phoneNumber']);

                return true;
            }), [])
            ->willReturn(['street' => '12 rue de la Paix'] + self::CURRENT);

        $result = $this->call(new UpdateAddress($client, $this->audit()), '12', street: '12 rue de la Paix', city: 'Paris', confirm: true);

        self::assertTrue($result['updated']);
        self::assertSame(['street' => ['before' => '21 rue de la Paix', 'after' => '12 rue de la Paix']], $result['diff'], 'a field given with its current value is no change');
    }

    public function testWithoutAConfirmationTheChangeIsPreviewedNotWritten(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(self::CURRENT);
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateAddress($client, $this->audit()), '12', street: '12 rue de la Paix');

        self::assertFalse($result['updated']);
        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertArrayHasKey('street', $result['diff']);
    }

    public function testNothingAskedOrNothingDifferentIsNotAWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(self::CURRENT);
        $client->expects(self::never())->method('put');
        $tool = new UpdateAddress($client, $this->audit());

        self::assertSame('no_change_requested', $this->call($tool, '12', confirm: true)['reason']);
        self::assertSame('no_change', $this->call($tool, '12', city: 'Paris', confirm: true)['reason']);
    }

    public function testAnUnknownAddressIsReportedNotThrown(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new UpdateAddress($client, $this->audit()), '999', city: 'Lyon', confirm: true);

        self::assertFalse($result['updated']);
        self::assertSame('not_found', $result['reason']);
    }
}
