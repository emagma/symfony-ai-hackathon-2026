<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxRate;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxRate\GetTaxRates;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GetTaxRates::class)]
final class GetTaxRatesTest extends McpToolTestCase
{
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['alpha' => 'tax-rates/alpha', 'ghost' => 'tax-rates/ghost'])
            ->willReturn([
                'alpha' => ['ok' => true, 'body' => ['code' => 'alpha', 'name' => 'Alpha']],
                'ghost' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetTaxRates($client), codes: ['alpha', 'ghost', 'alpha', ' ']);

        self::assertSame(['ghost'], $result['missing']);
        self::assertCount(1, $result['found']);
        self::assertSame('alpha', $result['found'][0]['code']);
        self::assertTrue($result['found'][0]['found']);
    }

    public function testTooManyCodesAreRefusedWithoutARequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new GetTaxRates($client), codes: array_map(static fn (int $i): string => 'c' . $i, range(1, 51)));

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_codes'], $result);
    }
}
