<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shared;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Exception\ClientException;

#[CoversClass(ReferenceResolver::class)]
final class ReferenceResolverTest extends McpToolTestCase
{
    public function testASingleLookupAnswersTheIriOrNullOnA404(): void
    {
        $client = $this->apiClient();
        $notFound = $this->clientException(404);
        $client->method('getDecoded')->willReturnCallback(static function (string|Route $path) use ($notFound): array {
            if ('taxons/dresses' === (string) $path) {
                return ['@id' => '/api/v2/admin/taxons/dresses', 'code' => 'dresses'];
            }

            throw $notFound;
        });
        $resolver = new ReferenceResolver($client);

        self::assertSame('/api/v2/admin/taxons/dresses', $resolver->iri(Resource::Taxon, 'dresses'));
        self::assertNull($resolver->iri(Resource::Taxon, 'hats'));
    }

    public function testASingleLookupLetsAnyOtherFailureThrough(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $this->expectException(ClientException::class);
        (new ReferenceResolver($client))->iri(Resource::Taxon, 'dresses');
    }

    public function testOneFanOutResolvesEveryResourceAndDeduplicatesTheCodes(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecodedMany')
            ->with(self::callback(static function (array $paths): bool {
                $paths = array_map(strval(...), array_values($paths));
                sort($paths);

                return ['channels/MOBILE', 'channels/WEB', 'product-options/size', 'taxons/dresses'] === $paths;
            }))
            ->willReturnCallback(static function (array $paths): array {
                $out = [];
                foreach ($paths as $key => $path) {
                    $path = (string) $path;
                    $out[$key] = ['ok' => true, 'body' => ['@id' => '/api/v2/admin/' . $path]];
                }

                return $out;
            });

        $resolved = (new ReferenceResolver($client))->resolve([
            'taxon' => ['dresses'],
            'channel' => ['WEB', 'MOBILE', 'WEB'],
            'product_option' => ['size'],
        ]);

        self::assertNull($resolved->missing);
        self::assertSame('/api/v2/admin/taxons/dresses', $resolved->iriOf('taxon', 'dresses'));
        self::assertSame(
            ['/api/v2/admin/channels/WEB', '/api/v2/admin/channels/MOBILE', '/api/v2/admin/channels/WEB'],
            $resolved->irisOf('channel', ['WEB', 'MOBILE', 'WEB']),
        );
    }

    public function testTheFirstUnknownCodeIsReportedWithItsResource(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths): array {
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $out[$key] = str_contains($path, 'ghost')
                    ? ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']
                    : ['ok' => true, 'body' => ['@id' => '/api/v2/admin/' . $path]];
            }

            return $out;
        });

        $resolved = (new ReferenceResolver($client))->resolve(['channel' => ['WEB', 'ghost']]);

        self::assertNotNull($resolved->missing);
        self::assertSame('channel', $resolved->missing->resource);
        self::assertSame('ghost', $resolved->missing->code);
        self::assertSame([], $resolved->iris);
    }

    public function testNothingToResolveMakesNoRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $resolved = (new ReferenceResolver($client))->resolve(['channel' => []]);

        self::assertNull($resolved->missing);
        self::assertSame([], $resolved->irisOf('channel', []));
    }

    public function testASubResourceKeyCarriesItsParents(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecodedMany')
            ->with(['product_option_value?option=size' . "\0" . 's' => Route::item(Resource::ProductOptionValue, 's', ['option' => 'size'])])
            ->willReturn(['product_option_value?option=size' . "\0" . 's' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/product-options/size/values/s']]]);

        $key = ReferenceResolver::key(Resource::ProductOptionValue, ['option' => 'size']);
        $known = (new ReferenceResolver($client))->lookupAll([$key => ['s']]);

        self::assertSame('product_option_value?option=size', $key);
        self::assertSame([$key => ['s' => '/api/v2/admin/product-options/size/values/s']], $known);
    }
}
