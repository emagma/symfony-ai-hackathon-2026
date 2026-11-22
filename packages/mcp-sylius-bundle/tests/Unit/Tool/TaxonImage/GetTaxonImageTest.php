<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxonImage;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxonImage\GetTaxonImage;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Exception\ClientException;

#[CoversClass(GetTaxonImage::class)]
final class GetTaxonImageTest extends McpToolTestCase
{
    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(GetTaxonImage::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('get_taxon_image', $attributes[0]->newInstance()->name);
    }

    public function testItFetchesImageAndProjectsCuratedFields(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('taxons/category/images/42', [], [])
            ->willReturn([
                'id' => 42,
                'path' => 'ab/cd/photo.webp',
                'type' => 'banner',
                'owner' => '/api/v2/admin/taxons/category',
            ]);

        $result = $this->call(new GetTaxonImage($client), code: 'category', id: 42);

        self::assertTrue($result['found']);
        self::assertSame(42, $result['id']);
        self::assertSame('category', $result['taxonCode']);
        self::assertSame('ab/cd/photo.webp', $result['path']);
        self::assertSame('banner', $result['type']);
    }

    public function testItReturnsFallbackCodeWhenOwnerIriIsAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'id' => 7,
            'path' => 'img.png',
            'type' => null,
        ]);

        $result = $this->call(new GetTaxonImage($client), code: 'fallback_taxon', id: 7);

        self::assertSame('fallback_taxon', $result['taxonCode']);
    }

    public function testItReturnsFoundFalseOnNotFound(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new GetTaxonImage($client), code: 'category', id: 99);

        self::assertSame(['found' => false, 'code' => 'category', 'id' => 99], $result);
    }

    public function testItRethrowsNon404ClientExceptions(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $this->expectException(ClientException::class);
        $this->call(new GetTaxonImage($client), code: 'category', id: 1);
    }
}
