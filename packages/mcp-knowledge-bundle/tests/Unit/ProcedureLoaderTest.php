<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Unit;

use Emagma\McpKnowledgeBundle\Procedure\ProcedureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\TextDocument;

#[CoversClass(ProcedureLoader::class)]
final class ProcedureLoaderTest extends TestCase
{
    public function testAProcedureIsReadSectionBySectionWithItsFrontMatter(): void
    {
        /** @var list<TextDocument> $documents */
        $documents = [...(new ProcedureLoader())->load(__DIR__ . '/fixtures', ['file' => 'returns/damaged-product.md'])];

        self::assertCount(3, $documents, 'the introduction and the two sections');
        $refund = $documents[2];
        self::assertSame('Damaged product returned', $refund->getMetadata()->getTitle());
        self::assertSame('Refund or exchange', $refund->getMetadata()[ProcedureLoader::KEY_SECTION]);
        self::assertSame('customer-service', $refund->getMetadata()[ProcedureLoader::KEY_TEAM]);
        self::assertSame('2026-11-10', $refund->getMetadata()[ProcedureLoader::KEY_UPDATED]);
        self::assertSame('returns/damaged-product.md', $refund->getMetadata()->getSource());
        self::assertStringStartsWith("Damaged product returned - Refund or exchange\n\nIf the damage happened in transit", $refund->getContent());
        self::assertStringNotContainsString('# Damaged product returned', $documents[0]->getContent(), 'the title is metadata, not text');
    }

    public function testWithoutFrontMatterTheFirstHeadingIsTheTitle(): void
    {
        /** @var list<TextDocument> $documents */
        $documents = [...(new ProcedureLoader())->load(__DIR__ . '/fixtures', ['file' => 'lost-parcel.md'])];

        self::assertCount(1, $documents);
        self::assertSame('Lost parcel', $documents[0]->getMetadata()->getTitle());
        self::assertNull($documents[0]->getMetadata()[ProcedureLoader::KEY_TEAM]);
    }

    public function testTheDirectoryIsReadWithItsSubDirectoriesAndAMissingOneIsEmpty(): void
    {
        $loader = new ProcedureLoader();

        self::assertSame(['lost-parcel.md', 'returns/damaged-product.md'], $loader->files(__DIR__ . '/fixtures'));
        self::assertSame([], $loader->files(__DIR__ . '/nowhere'));
        self::assertSame([], [...$loader->load(__DIR__ . '/nowhere')]);
    }
}
