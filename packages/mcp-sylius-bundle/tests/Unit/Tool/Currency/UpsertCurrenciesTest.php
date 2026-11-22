<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Currency;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Currency\UpsertCurrencies;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertCurrencies::class)]
final class UpsertCurrenciesTest extends McpToolTestCase
{
    public function testItCreatesTheMissingCodesAndReportsTheExistingOnesUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'JPY' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'EUR' => ['ok' => true, 'body' => ['code' => 'EUR']],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['JPY' => ['path' => 'currencies', 'body' => ['code' => 'JPY']]])
            ->willReturn(['JPY' => ['ok' => true, 'body' => ['code' => 'JPY']]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertCurrencies($client, $this->audit()), items: [['code' => 'JPY'], ['code' => 'EUR']], confirm: true);

        self::assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 1, 'failed' => 0], array_intersect_key($result, ['created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1]));
        self::assertSame(['code' => ['before' => null, 'after' => 'JPY']], $result['results']['JPY']['diff']);
    }

    public function testModeCreateRefusesAnExistingCurrency(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['EUR' => ['ok' => true, 'body' => ['code' => 'EUR']]]);

        $result = $this->call(new UpsertCurrencies($client, $this->audit()), items: [['code' => 'EUR']], mode: 'create', confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'already_exists'], $result['results']['EUR']);
    }
}
