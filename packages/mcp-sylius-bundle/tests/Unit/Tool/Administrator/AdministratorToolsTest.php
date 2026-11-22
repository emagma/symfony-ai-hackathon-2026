<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Administrator;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Administrator\SearchAdministrators;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchAdministrators::class)]
#[CoversClass(AdministratorShaper::class)]
final class AdministratorToolsTest extends McpToolTestCase
{
    private const string PATH = 'administrators/3';

    private const string SERVICE_ACCOUNT = 'api@example.com';

    private const string PASSWORD = 'correct-horse-battery-staple';

    /** The admin Administrator collection declares no filters at all. */
    public function testListFetchesOnceAndFiltersItself(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('administrators', ['page' => 1, 'itemsPerPage' => 500])
            ->willReturn($this->collection([
                $this->administrator(),
                ['id' => 4, 'username' => 'kevin', 'email' => 'kevin@shop.test', 'enabled' => false],
            ], total: 2));

        $result = $this->call(new SearchAdministrators($client), enabled: false);

        self::assertSame(1, $result['total']);
        self::assertSame('kevin', $result['items'][0]['username']);
    }

    public function testListMatchesEmailAndUsernamePartiallyAndCaseInsensitively(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([
            $this->administrator(),
            ['id' => 4, 'username' => 'kevin', 'email' => 'kevin@shop.test', 'enabled' => true],
        ], total: 2));

        $tool = new SearchAdministrators($client);

        self::assertSame(['api'], array_column($tool(email: 'API@EXAMPLE')['items'], 'username'));
        self::assertSame(['kevin'], array_column($tool(username: 'KEV')['items'], 'username'));
    }

    public function testListKeepsLastLoginWhichIsWhatMarksADormantAccount(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->administrator()], total: 1));

        $item = $this->call(new SearchAdministrators($client))['items'][0];

        self::assertSame('2026-09-07 16:13:07', $item['lastLogin']);
        self::assertArrayNotHasKey('found', $item);
    }

    /**
     * @return array<string, mixed>
     */
    private function administrator(): array
    {
        return [
            '@id' => '/api/v2/admin/administrators/3',
            'id' => 3,
            'username' => 'api',
            'email' => self::SERVICE_ACCOUNT,
            'firstName' => 'Luke',
            'lastName' => 'Brushwood',
            'localeCode' => 'fr_FR',
            'enabled' => true,
            'lastLogin' => '2026-09-07 16:13:07',
            'verifiedAt' => null,
            'createdAt' => '2026-07-12 22:00:49',
        ];
    }
}
