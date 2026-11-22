<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\PaymentMethod;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\PaymentMethod\UpsertPaymentMethods;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertPaymentMethods::class)]
final class UpsertPaymentMethodsTest extends McpToolTestCase
{
    public function testACreationDefaultsTheGatewayAndNeedsNameAndChannels(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn([
            'bank' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'cash' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['bank' => ['path' => 'payment-methods', 'body' => [
                'code' => 'bank',
                'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
                'gatewayConfig' => ['factoryName' => 'offline', 'gatewayName' => 'Bank transfer', 'config' => []],
                'translations' => ['en_US' => ['name' => 'Bank transfer', 'instructions' => 'IBAN FR76…', 'locale' => 'en_US']],
                'enabled' => true,
            ]]])
            ->willReturn(['bank' => ['ok' => true, 'body' => ['code' => 'bank']]]);

        $result = $this->call(new UpsertPaymentMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'bank', 'name' => 'Bank transfer', 'instructions' => 'IBAN FR76…', 'channelCodes' => ['FASHION_WEB']],
            ['code' => 'cash', 'name' => 'Cash'],
        ], confirm: true);

        self::assertSame('created', $result['results']['bank']['action']);
        self::assertSame('offline', $result['results']['bank']['diff']['factoryName']['after']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['channelCodes']], $result['results']['cash']);
    }

    public function testAnUpdateIgnoresTheFactoryAndReplacesTheGatewayConfigOnly(): void
    {
        $bank = [
            'code' => 'bank',
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
            'gatewayConfig' => ['@id' => '/g/1', 'factoryName' => 'offline', 'gatewayName' => 'Bank transfer', 'config' => ['iban' => 'old']],
            'translations' => ['en_US' => ['@id' => '/t/1', 'name' => 'Bank transfer', 'locale' => 'en_US']],
            'enabled' => true,
            'position' => 0,
        ];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['bank' => ['ok' => true, 'body' => $bank]]);
        $client->expects(self::once())->method('putMany')
            ->with(['bank' => ['path' => 'payment-methods/bank', 'body' => array_replace($bank, [
                'gatewayConfig' => ['@id' => '/g/1', 'factoryName' => 'offline', 'gatewayName' => 'Bank transfer', 'config' => ['iban' => 'new']],
                'translations' => ['en_US' => ['@id' => '/t/1', 'name' => 'Bank transfer', 'locale' => 'en_US', 'description' => 'Wire']],
            ])]])
            ->willReturn(['bank' => ['ok' => true, 'body' => $bank]]);

        $result = $this->call(new UpsertPaymentMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [
            ['code' => 'bank', 'factoryName' => 'paypal', 'gatewayName' => 'PayPal', 'description' => 'Wire', 'gatewayConfig' => ['iban' => 'new'], 'channelCodes' => ['FASHION_WEB']],
        ], confirm: true);

        self::assertSame(
            ['en_US.description' => ['before' => null, 'after' => 'Wire'], 'gatewayConfig.config' => ['before' => ['iban' => 'old'], 'after' => ['iban' => 'new']]],
            $result['results']['bank']['diff'],
        );
    }

    public function testNothingToChangeIsUnchanged(): void
    {
        $bank = ['code' => 'bank', 'channels' => [], 'gatewayConfig' => ['config' => []], 'translations' => ['en_US' => ['name' => 'Bank']], 'enabled' => true];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['bank' => ['ok' => true, 'body' => $bank]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertPaymentMethods($client, $this->audit(), 'en_US', $this->adapter()), items: [['code' => 'bank', 'name' => 'Bank', 'enabled' => true]]);

        self::assertSame('unchanged', $result['results']['bank']['action']);
    }
}
