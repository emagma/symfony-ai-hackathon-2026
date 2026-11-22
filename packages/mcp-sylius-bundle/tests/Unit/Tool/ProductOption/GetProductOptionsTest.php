<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductOption;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductOption\GetProductOptions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GetProductOptions::class)]
final class GetProductOptionsTest extends McpToolTestCase
{
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['alpha' => 'product-options/alpha', 'ghost' => 'product-options/ghost'])
            ->willReturn([
                'alpha' => ['ok' => true, 'body' => ['code' => 'alpha', 'name' => 'Alpha']],
                'ghost' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetProductOptions($client, 'en_US', $this->adapter()), codes: ['alpha', 'ghost', 'alpha', ' ']);

        self::assertSame(['ghost'], $result['missing']);
        self::assertCount(1, $result['found']);
        self::assertSame('alpha', $result['found'][0]['code']);
        self::assertTrue($result['found'][0]['found']);
    }

    public function testTheValuesAreResolvedInOneMoreFanOutWithTheirCodesAndLabels(): void
    {
        $client = $this->apiClient();
        $client->expects(self::exactly(2))->method('getDecodedMany')->willReturnOnConsecutiveCalls(
            ['size' => ['ok' => true, 'body' => ['code' => 'size', 'values' => ['/api/v2/admin/product-options/size/values/size_s', '/api/v2/admin/product-options/size/values/size_m']]]],
            [
                '/api/v2/admin/product-options/size/values/size_s' => ['ok' => true, 'body' => ['code' => 'size_s', 'translations' => ['en_US' => ['value' => 'S']]]],
                '/api/v2/admin/product-options/size/values/size_m' => ['ok' => true, 'body' => ['code' => 'size_m', 'translations' => ['en_US' => ['value' => 'M']]]],
            ],
        );

        $result = $this->call(new GetProductOptions($client, 'en_US', $this->adapter()), codes: ['size']);

        self::assertSame([['code' => 'size_s', 'value' => 'S'], ['code' => 'size_m', 'value' => 'M']], $result['found'][0]['values']);
        self::assertSame(2, $result['found'][0]['valuesCount']);
    }

    public function testTooManyCodesAreRefusedWithoutARequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new GetProductOptions($client, 'en_US', $this->adapter()), codes: array_map(static fn (int $i): string => 'c' . $i, range(1, 51)));

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_codes'], $result);
    }
}
