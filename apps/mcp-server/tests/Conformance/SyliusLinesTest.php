<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\AdminApi\Adapter\ApiResource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusV2Bundle\Sylius2Line;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SyliusLinesTest extends TestCase
{
    private const string API = 'http://shop.test/api/v2/admin/';

    /**
     * @return iterable<string, array{SyliusLineInterface}>
     */
    public static function lines(): iterable
    {
        yield '2.x' => [new Sylius2Line()];
    }

    public function testTheLinesServeEveryVersionTheServerDocumentsAndShareNone(): void
    {
        self::assertSame(
            ['2.0', '2.1', '2.2', '2.3'],
            (new SyliusLines(array_map(static fn (array $line): SyliusLineInterface => $line[0], iterator_to_array(self::lines(), false))))->versions(),
        );
    }

    #[DataProvider('lines')]
    public function testEveryResourceOfEveryBundleHasItsRoutesOnTheLine(SyliusLineInterface $line): void
    {
        $adapter = $line->adapter(self::API);
        $parents = ['product' => 'MUG', 'taxon' => 'mugs', 'option' => 'size', 'promotion' => 'BF', 'country' => 'FR'];

        /** @var list<ApiResource> $resources */
        $resources = Resource::cases();
        foreach ($resources as $resource) {
            $collection = $adapter->path(Route::collection($resource, $parents));
            self::assertStringNotContainsString('{', $collection, $resource->routeKey());

            if (Resource::PluginCapabilities === $resource) {
                continue; // a singleton
            }
            self::assertStringEndsWith('/a%20b%2Fc', $adapter->path(Route::item($resource, 'a b/c', $parents)), $resource->routeKey());
        }
    }
}
