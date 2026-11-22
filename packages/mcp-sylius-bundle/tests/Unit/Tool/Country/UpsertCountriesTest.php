<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Country;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Country\UpsertCountries;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertCountries::class)]
final class UpsertCountriesTest extends McpToolTestCase
{
    public function testItPostsTheSameBodiesTheSingleToolDidAndDisablesAnExistingOne(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'JP' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'FR' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'DE' => ['ok' => true, 'body' => ['code' => 'DE', 'name' => 'Germany', 'enabled' => true, 'provinces' => []]],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['JP' => ['path' => 'countries', 'body' => ['code' => 'JP']], 'FR' => ['path' => 'countries', 'body' => ['code' => 'FR', 'enabled' => false]]])
            ->willReturn(['JP' => ['ok' => true, 'body' => ['code' => 'JP']], 'FR' => ['ok' => true, 'body' => ['code' => 'FR']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['DE' => ['path' => 'countries/DE', 'body' => ['code' => 'DE', 'name' => 'Germany', 'enabled' => false, 'provinces' => []]]])
            ->willReturn(['DE' => ['ok' => true, 'body' => ['code' => 'DE', 'enabled' => false]]]);

        $result = $this->call(new UpsertCountries($client, $this->audit()), items: [
            ['code' => 'JP'], ['code' => 'FR', 'enabled' => false], ['code' => 'DE', 'enabled' => false],
        ], confirm: true);

        self::assertTrue($result['ok']);
        self::assertSame(['created' => 2, 'updated' => 1, 'unchanged' => 0, 'failed' => 0], array_intersect_key($result, ['created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1]));
        self::assertSame(['enabled' => ['before' => true, 'after' => false]], $result['results']['DE']['diff']);
    }

    public function testAnItemWithoutAChangeIsUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['DE' => ['ok' => true, 'body' => ['code' => 'DE', 'enabled' => true]]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertCountries($client, $this->audit()), items: [['code' => 'DE', 'enabled' => true]]);

        self::assertSame(['ok' => true, 'action' => 'unchanged'], $result['results']['DE']);
    }

    public function testANonBooleanEnabledRefusesTheBatch(): void
    {
        $result = $this->call(new UpsertCountries($this->apiClient(), $this->audit()), items: [['code' => 'DE', 'enabled' => 'yes']], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame('enabled is a boolean', $result['errors'][0]['message']);
    }
}
