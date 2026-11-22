<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Taxon;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Taxon\ListTaxonTree;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ListTaxonTree::class)]
final class ListTaxonTreeTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(ListTaxonTree::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('list_taxon_tree', $attributes[0]->newInstance()->name);
    }

    public function testItWalksTreeInDfsPreOrder(): void
    {
        $members = [
            $this->node('animals', 'Animals', parent: null),
            $this->node('cats', 'Cats', parent: '/api/v2/admin/taxons/animals'),
            $this->node('kittens', 'Kittens', parent: '/api/v2/admin/taxons/cats'),
            $this->node('dogs', 'Dogs', parent: '/api/v2/admin/taxons/animals'),
        ];

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection($members));

        $result = $this->call(new ListTaxonTree($client, self::LOCALE));

        $codes = array_column($result['items'], 'code');
        self::assertSame(['animals', 'cats', 'kittens', 'dogs'], $codes);

        self::assertSame(0, $result['items'][0]['level']); // animals
        self::assertSame(1, $result['items'][1]['level']); // cats
        self::assertSame(2, $result['items'][2]['level']); // kittens
        self::assertSame(1, $result['items'][3]['level']); // dogs

        self::assertNull($result['items'][0]['parentCode']);
        self::assertSame('animals', $result['items'][1]['parentCode']);
        self::assertSame('cats', $result['items'][2]['parentCode']);
    }

    public function testItReadsTheWholeTaxonomyPageByPageWithoutRootCode(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['depth' => 10, 'page' => 1, 'itemsPerPage' => 1000], ['Prefer' => 'respond-for=lean; locale=en_US'])
            ->willReturn($this->collection([]));

        $this->call(new ListTaxonTree($client, self::LOCALE));
    }

    public function testItAsksTheServerForTheSubtreeWhenRootCodeIsGiven(): void
    {
        $members = [
            $this->node('cats', 'Cats', parent: '/api/v2/admin/taxons/animals'),
            $this->node('kittens', 'Kittens', parent: '/api/v2/admin/taxons/cats'),
        ];

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['subtreeOf' => 'cats', 'depth' => 10, 'page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection($members));

        $result = $this->call(new ListTaxonTree($client, self::LOCALE), rootCode: 'cats');

        self::assertSame(['cats', 'kittens'], array_column($result['items'], 'code'));
        self::assertSame([0, 1], array_column($result['items'], 'level'));
        self::assertSame('animals', $result['items'][0]['parentCode'], 'the branch root keeps its real parent');
    }

    public function testItFollowsPaginationToTheEndInsteadOfTruncating(): void
    {
        $page1 = array_map(fn (int $i): array => $this->node('t_' . $i, 'T ' . $i, parent: null), range(1, 1000));
        $page2 = array_map(fn (int $i): array => $this->node('t_' . $i, 'T ' . $i, parent: null), range(1001, 1050));

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::exactly(2))
            ->method('getDecoded')
            ->willReturnCallback(fn (\Stringable|string $path, array $query): array => match ($query['page']) {
                1 => $this->collection($page1, total: 1050),
                2 => $this->collection($page2, total: 1050),
            });

        $result = $this->call(new ListTaxonTree($client, self::LOCALE));

        self::assertSame(1050, $result['total']);
        self::assertSame('t_1050', $result['items'][1049]['code']);
    }

    public function testItStopsOnAnEmptyPageEvenIfTheTotalPromisesMore(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::exactly(2))
            ->method('getDecoded')
            ->willReturnCallback(fn (\Stringable|string $path, array $query): array => match ($query['page']) {
                1 => $this->collection([$this->node('a', 'A', parent: null)], total: 5),
                2 => $this->collection([], total: 5),
            });

        $result = $this->call(new ListTaxonTree($client, self::LOCALE));

        self::assertSame(['a'], array_column($result['items'], 'code'));
    }

    public function testItSendsDepthToTheServerAndStillCapsLocally(): void
    {
        // A payload deeper than asked (older plugin, or a filter that skipped) is still cut here.
        $members = [
            $this->node('root', 'Root', parent: null),
            $this->node('level1', 'Level1', parent: '/api/v2/admin/taxons/root'),
            $this->node('level2', 'Level2', parent: '/api/v2/admin/taxons/level1'),
        ];

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['depth' => 1, 'page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection($members));

        $result = $this->call(new ListTaxonTree($client, self::LOCALE), depth: 1);

        $codes = array_column($result['items'], 'code');
        self::assertSame(['root', 'level1'], $codes); // level2 excluded
        self::assertSame(1, $result['depthCap']);
    }

    public function testItReturnsEmptyWhenRootCodeNotFound(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['subtreeOf' => 'nonexistent', 'depth' => 10, 'page' => 1, 'itemsPerPage' => 1000])
            ->willReturn($this->collection([], total: 0));

        $result = $this->call(new ListTaxonTree($client, self::LOCALE), rootCode: 'nonexistent');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
    }

    private function node(string $code, string $name, ?string $parent): array
    {
        return [
            'code' => $code,
            'enabled' => true,
            'parent' => $parent,
            'translations' => [self::LOCALE => ['name' => $name, 'slug' => $code]],
        ];
    }
}
