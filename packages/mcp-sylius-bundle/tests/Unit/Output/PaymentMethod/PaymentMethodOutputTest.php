<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\PaymentMethod;

use Emagma\McpSyliusBundle\Output\PaymentMethod\PaymentMethodOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentMethodOutput::class)]
final class PaymentMethodOutputTest extends TestCase
{
    private const string LOCALE = 'en_US';

    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'code' => 'cash_on_delivery',
            'enabled' => true,
            'position' => 1,
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
            'gatewayConfig' => [
                'factoryName' => 'offline',
                'gatewayName' => 'Cash on delivery',
                'config' => [],
            ],
            'translations' => [
                self::LOCALE => [
                    'name' => 'Cash on delivery',
                    'description' => 'Pay on receipt',
                    'instructions' => 'Have cash ready',
                ],
            ],
        ];

        $shaped = PaymentMethodOutput::fromPayload($raw, self::LOCALE, 'cash_on_delivery');

        self::assertTrue($shaped->found);
        self::assertSame('cash_on_delivery', $shaped->code);
        self::assertSame('Cash on delivery', $shaped->name);
        self::assertSame('Pay on receipt', $shaped->description);
        self::assertSame('Have cash ready', $shaped->instructions);
        self::assertTrue($shaped->enabled);
        self::assertSame(1, $shaped->position);
        self::assertSame(['FASHION_WEB'], $shaped->channelCodes);
        self::assertSame('Cash on delivery', $shaped->gatewayCode);
    }

    public function testItFallsBackToFallbackCodeWhenCodeMissing(): void
    {
        $shaped = PaymentMethodOutput::fromPayload([], self::LOCALE, 'fallback_pm');

        self::assertSame('fallback_pm', $shaped->code);
    }

    public function testItReturnsNullGatewayCodeWhenGatewayConfigAbsent(): void
    {
        $shaped = PaymentMethodOutput::fromPayload(
            ['translations' => [], 'channels' => []],
            self::LOCALE,
            'pm',
        );

        self::assertNull($shaped->gatewayCode);
    }

    public function testItReturnsNullFieldsWhenTranslationMissing(): void
    {
        $shaped = PaymentMethodOutput::fromPayload(['translations' => []], self::LOCALE, 'pm');

        self::assertNull($shaped->name);
        self::assertNull($shaped->description);
        self::assertNull($shaped->instructions);
    }

    public function testItUsesAlternativeLocale(): void
    {
        $raw = [
            'translations' => [
                'fr_FR' => ['name' => 'Paiement en espèces', 'description' => 'desc fr', 'instructions' => 'instr fr'],
            ],
            'channels' => [],
        ];

        $shaped = PaymentMethodOutput::fromPayload($raw, 'fr_FR', 'pm');

        self::assertSame('Paiement en espèces', $shaped->name);
    }
}
