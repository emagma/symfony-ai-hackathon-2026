<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Taxon;

use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Taxon\SearchTaxons;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchTaxons::class)]
final class SearchTaxonsTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(SearchTaxons::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('search_taxons', $attributes[0]->newInstance()->name);
        self::assertStringContainsString('server-side', $attributes[0]->newInstance()->description);
    }

    public function testItRequestsOnePageInLeanModeWithTheLocale(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['page' => 1, 'itemsPerPage' => 30], ['Prefer' => 'respond-for=lean; locale=fr_FR'])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchTaxons($client, self::LOCALE), locale: 'fr_FR');
    }

    public function testItSendsEveryCriterionToTheServer(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', [
                'name' => 'cat',
                'code' => 'ca',
                'parentCode' => 'animals',
                'page' => 2,
                'itemsPerPage' => 10,
                'rootOnly' => 'true',
                'enabled' => 'false',
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(
            new SearchTaxons($client, self::LOCALE),
            name: 'cat',
            code: 'ca',
            parentCode: 'animals',
            rootOnly: true,
            enabled: false,
            page: 2,
            itemsPerPage: 10,
        );
    }

    public function testItOmitsBlankCriteriaAndUnsetFlags(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchTaxons($client, self::LOCALE), name: '', code: '', parentCode: '');
    }

    public function testItProjectsMembersToCodeAndName(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->taxonMember('cats', 'Cats'),
        ], total: 1));

        $result = $this->call(new SearchTaxons($client, self::LOCALE));

        self::assertSame([['code' => 'cats', 'name' => 'Cats']], $result['items']);
        self::assertCount(2, $result['items'][0], 'items[i] MUST contain only code and name');
    }

    public function testItTrustsTheServerTotalForPagination(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($this->collection([
            $this->taxonMember('cat_31', 'Cat 31'),
        ], total: 1234));

        $result = $this->call(new SearchTaxons($client, self::LOCALE), page: 2, itemsPerPage: 30);

        self::assertCount(1, $result['items']);
        self::assertSame(1234, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertTrue($result['hasMore']);
    }

    public function testItCapsItemsPerPageAtFifty(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons', ['page' => 1, 'itemsPerPage' => 50])
            ->willReturn($this->collection([]));

        $this->call(new SearchTaxons($client, self::LOCALE), itemsPerPage: 999);
    }

    private function taxonMember(string $code, string $name, ?string $parent = null, bool $enabled = true): array
    {
        return [
            'code' => $code,
            'enabled' => $enabled,
            'parent' => $parent,
            'translations' => [self::LOCALE => ['name' => $name, 'slug' => $code]],
        ];
    }
}
