<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Promotion;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Promotion\ArchivePromotion;
use Emagma\McpSyliusBundle\Tool\Promotion\RestorePromotion;
use Emagma\McpSyliusBundle\Tool\Promotion\SearchPromotions;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchPromotions::class)]
#[CoversClass(ArchivePromotion::class)]
#[CoversClass(RestorePromotion::class)]
#[CoversClass(PromotionShaper::class)]
final class PromotionToolsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string PATH = 'promotions/summer';

    public function testListSendsTheServerSideCriteria(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('promotions', [
                'page' => 1,
                'itemsPerPage' => 30,
                'name' => 'summer',
                'channel' => 'WEB',
                'activeAt' => '2026-07-15',
                'couponBased' => 'true',
                'exclusive' => 'false',
            ], ['Prefer' => 'respond-for=lean'])
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchPromotions($client),
            name: 'summer',
            channel: 'WEB',
            couponBased: true,
            exclusive: false,
            activeAt: '2026-07-15',
        );
    }

    /** `false` means "the automatic promotions": an array_filter would drop it. */
    public function testListKeepsAFalseFlagInTheQuery(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('promotions', self::callback(static function (array $query): bool {
                self::assertSame('false', $query['couponBased']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchPromotions($client), couponBased: false);
    }

    public function testListAsksForArchivedPromotionsThroughTheExistsFilter(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('promotions', self::callback(static function (array $query): bool {
                // Sylius hides archived promotions unless this is present.
                self::assertSame(['archivedAt' => 'true'], $query['exists']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchPromotions($client), archived: true);
    }

    /** `priority` is the only ordering the vendor OrderFilter declares; Sylius silently ignores anything else. */
    public function testListOnlyForwardsThePrioritySort(): void
    {
        $seen = [];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(
            function (\Stringable|string $path, array $query) use (&$seen): array {
                $path = (string) $path;
                $seen[] = $query['order'] ?? null;

                return $this->collection([]);
            },
        );

        $tool = new SearchPromotions($client);
        $tool(sort: 'priority', direction: 'asc');
        $tool(sort: 'priority');
        $tool(sort: 'name');

        self::assertSame([['priority' => 'asc'], ['priority' => 'desc'], null], $seen);
    }

    public function testListProjectsTheScanningFieldsWithoutRulesOrActions(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->promotion()], total: 1));

        $items = $this->call(new SearchPromotions($client))['items'];

        self::assertSame(['WEB'], $items[0]['channels']);
        self::assertSame(3, $items[0]['used']);
        self::assertArrayNotHasKey('rules', $items[0]);
        self::assertArrayNotHasKey('actions', $items[0]);
    }

    public function testArchivePatchesTheActionEndpointWithNoBody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->promotion());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/archive', [], [])
            ->willReturn(['archivedAt' => '2026-09-07 15:00:00'] + $this->promotion());

        $result = $this->call(new ArchivePromotion($client, $this->audit(), self::LOCALE), 'summer', confirm: true);

        self::assertTrue($result['archived']);
        self::assertSame('2026-09-07 15:00:00', $result['after']['archivedAt']);
    }

    public function testArchiveRefusesAnAlreadyArchivedPromotion(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['archivedAt' => '2026-01-01 00:00:00'] + $this->promotion());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'already_archived',
            $this->call(new ArchivePromotion($client, $this->audit(), self::LOCALE), 'summer', confirm: true)['reason'],
        );
    }

    public function testRestoreRefusesAPromotionThatIsNotArchived(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->promotion());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'not_archived',
            $this->call(new RestorePromotion($client, $this->audit(), self::LOCALE), 'summer', confirm: true)['reason'],
        );
    }

    public function testRestorePatchesItsOwnActionEndpoint(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['archivedAt' => '2026-01-01 00:00:00'] + $this->promotion());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/restore', [], [])
            ->willReturn($this->promotion());

        $result = $this->call(new RestorePromotion($client, $this->audit(), self::LOCALE), 'summer', confirm: true);

        self::assertTrue($result['restored']);
        self::assertNull($result['after']['archivedAt']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->promotion());
        $client->expects(self::once())->method('patch')->willReturn($this->promotion());

        $tool = new ArchivePromotion($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('summer', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['archived']);
    }

    /**
     * @return array<string, mixed>
     */
    private function promotion(): array
    {
        return [
            '@id' => '/api/v2/admin/promotions/summer',
            'code' => 'summer',
            'name' => 'Summer sale',
            'description' => 'Seasonal discount.',
            'priority' => 5,
            'exclusive' => false,
            'appliesToDiscounted' => true,
            'couponBased' => false,
            'usageLimit' => 100,
            'used' => 3,
            'startsAt' => '2026-06-01 00:00:00',
            'endsAt' => '2026-08-31 23:59:59',
            'archivedAt' => null,
            'channels' => ['/api/v2/admin/channels/WEB'],
            'coupons' => [
                '/api/v2/admin/promotions/summer/coupons/SUM1',
                '/api/v2/admin/promotions/summer/coupons/SUM2',
            ],
            'rules' => [[
                '@id' => '/api/v2/admin/promotions/summer/rules/1',
                'id' => 1,
                'type' => 'cart_quantity',
                'configuration' => ['count' => 3],
            ]],
            'actions' => [[
                '@id' => '/api/v2/admin/promotions/summer/actions/1',
                'id' => 1,
                'type' => 'order_percentage_discount',
                'configuration' => ['percentage' => 0.15],
            ]],
            'translations' => [
                'en_US' => [
                    '@id' => '/api/v2/admin/promotions/summer/translations/en_US',
                    'label' => 'Summer sale',
                ],
            ],
        ];
    }
}
