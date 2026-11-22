<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\TaxonImage;

use Emagma\McpSyliusBundle\Output\TaxonImage\TaxonImageOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxonImageOutput::class)]
final class TaxonImageOutputTest extends TestCase
{
    public function testItShapesAFullPayload(): void
    {
        $raw = [
            'id' => 42,
            'path' => 'ab/cd/photo.webp',
            'type' => 'banner',
            'owner' => '/api/v2/admin/taxons/category',
        ];

        $shaped = TaxonImageOutput::fromPayload($raw, 'fallback', 0);

        self::assertTrue($shaped->found);
        self::assertSame(42, $shaped->id);
        self::assertSame('category', $shaped->taxonCode);
        self::assertSame('ab/cd/photo.webp', $shaped->path);
        self::assertSame('banner', $shaped->type);
    }

    public function testItFallsBackToProvidedCodeWhenOwnerIriIsAbsent(): void
    {
        $raw = ['id' => 7, 'path' => 'x.png'];

        $shaped = TaxonImageOutput::fromPayload($raw, 'my_taxon', 7);

        self::assertSame('my_taxon', $shaped->taxonCode);
    }

    public function testItFallsBackToProvidedIdWhenIdKeyIsAbsent(): void
    {
        $raw = ['path' => 'x.png'];

        $shaped = TaxonImageOutput::fromPayload($raw, 'cat', 99);

        self::assertSame(99, $shaped->id);
    }

    public function testItReturnsNullPathAndTypeWhenAbsent(): void
    {
        $raw = ['id' => 3];

        $shaped = TaxonImageOutput::fromPayload($raw, 'cat', 3);

        self::assertNull($shaped->path);
        self::assertNull($shaped->type);
    }
}
