<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\CustomerGroup;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\CustomerGroup\GetCustomerGroups;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GetCustomerGroups::class)]
final class GetCustomerGroupsTest extends McpToolTestCase
{
    public function testItAnswersTheOnesThatExistAndNamesTheOthers(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['standard' => 'customer-groups/standard', 'ghost' => 'customer-groups/ghost'])
            ->willReturn([
                'standard' => ['ok' => true, 'body' => ['code' => 'standard', 'name' => 'Standard']],
                'ghost' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            ]);

        $result = $this->call(new GetCustomerGroups($client), codes: ['standard', 'ghost', 'standard', ' ']);

        self::assertSame([
            'found' => [['found' => true, 'code' => 'standard', 'name' => 'Standard']],
            'missing' => ['ghost'],
        ], $result);
    }

    public function testTooManyCodesAreRefusedWithoutARequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new GetCustomerGroups($client), codes: array_map(static fn (int $i): string => 'c' . $i, range(1, 51)));

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'too_many_codes'], $result);
    }
}
