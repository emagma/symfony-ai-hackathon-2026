<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario12CarrierRatesTest extends QaScenarioTestCase
{
    /** code => channel => flat rate in minor units */
    private const array RATES = [
        'ups' => ['FASHION_WEB' => 216, 'WEB_EUR' => 188],
        'dhl_express' => ['FASHION_WEB' => 216, 'WEB_EUR' => 188],
        'fedex' => ['FASHION_WEB' => 216, 'WEB_EUR' => 188],
        'dhl_eea' => ['FASHION_WEB' => 550, 'WEB_EUR' => 479],
    ];

    public function testTheFlatRateOfEveryCarrierIsSetPerChannel(): void
    {
        $carriers = array_column($this->searchAll('search_shipping_methods'), 'name', 'code');
        foreach (['ups' => 'UPS', 'dhl_express' => 'DHL Express', 'fedex' => 'FedEx', 'dhl_eea' => 'DHL EEA'] as $code => $name) {
            self::assertSame($name, $carriers[$code] ?? null, $name . ' is a carrier of the shop');
        }

        $items = [];
        foreach (self::RATES as $code => $rates) {
            $items[] = ['code' => $code, 'configuration' => array_map(static fn (int $amount): array => ['amount' => $amount], $rates)];
        }
        self::assertBatchApplied($this->call('upsert_shipping_methods', ['items' => $items, 'mode' => 'update', 'confirm' => true]));

        /** @var list<array{code: string, calculator: string, channelCodes: list<string>, chargesPerChannel: array<string, array{amount: int}>}> $found */
        $found = $this->call('get_shipping_methods', ['codes' => array_keys(self::RATES)])['found'];
        foreach ($found as $method) {
            self::assertSame('flat_rate', $method['calculator'], $method['code']);
            self::assertEqualsCanonicalizing(['FASHION_WEB', 'WEB_EUR'], $method['channelCodes'], $method['code'] . ' keeps its channels');
            self::assertEquals(
                array_map(static fn (int $amount): array => ['amount' => $amount], self::RATES[$method['code']]),
                $method['chargesPerChannel'],
                $method['code'] . ' rates',
            );
        }
        self::assertCount(\count(self::RATES), $found);
    }
}
