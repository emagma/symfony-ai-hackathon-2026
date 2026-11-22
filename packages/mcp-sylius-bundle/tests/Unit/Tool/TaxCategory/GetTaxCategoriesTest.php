<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxCategory;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxCategory\GetTaxCategories;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GetTaxCategories::class)]
final class GetTaxCategoriesTest extends McpToolTestCase
{
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['standard' => 'tax-categories/standard', 'ghost' => 'tax-categories/ghost'])
            ->willReturn([
                'standard' => ['ok' => true, 'body' => ['code' => 'standard', 'name' => 'Standard', 'description' => null]],
                'ghost' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetTaxCategories($client), codes: ['standard', 'ghost', 'standard', ' ']);

        self::assertSame([
            'found' => [['found' => true, 'code' => 'standard', 'name' => 'Standard', 'description' => null]],
            'missing' => ['ghost'],
        ], $result);
    }

    public function testTooManyCodesAreRefusedWithoutARequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new GetTaxCategories($client), codes: array_map(static fn (int $i): string => 'c' . $i, range(1, 51)));

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_codes'], $result);
    }
}
