<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingCategory;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingCategory\UpsertShippingCategories;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertShippingCategories::class)]
final class UpsertShippingCategoriesTest extends McpToolTestCase
{
    public function testItSendsTheBodiesTheSingleToolsSent(): void
    {
        $current = ['code' => 'sc_standard', 'name' => 'Standard Shipping', 'description' => null];
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'sc_heavy' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'sc_standard' => ['ok' => true, 'body' => $current],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['sc_heavy' => ['path' => 'shipping-categories', 'body' => ['code' => 'sc_heavy', 'name' => 'Heavy Items', 'description' => 'For items over 30kg']]])
            ->willReturn(['sc_heavy' => ['ok' => true, 'body' => ['code' => 'sc_heavy']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['sc_standard' => ['path' => 'shipping-categories/sc_standard', 'body' => ['code' => 'sc_standard', 'name' => 'Express Shipping', 'description' => null]]])
            ->willReturn(['sc_standard' => ['ok' => true, 'body' => ['code' => 'sc_standard', 'name' => 'Express Shipping']]]);

        $result = $this->call(new UpsertShippingCategories($client, $this->audit()), items: [
            ['code' => 'sc_heavy', 'name' => 'Heavy Items', 'description' => 'For items over 30kg'],
            ['code' => 'sc_standard', 'name' => 'Express Shipping'],
        ], confirm: true);

        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'failed' => 0], array_intersect_key($result, ['created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1]));
        self::assertSame(['name' => ['before' => 'Standard Shipping', 'after' => 'Express Shipping']], $result['results']['sc_standard']['diff']);
    }

    public function testABlankNameRefusesTheBatch(): void
    {
        $result = $this->call(new UpsertShippingCategories($this->apiClient(), $this->audit()), items: [['code' => 'x', 'name' => ' ']], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
    }
}
