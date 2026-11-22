<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Output\Locale;

use Emagma\McpSyliusBundle\Output\Locale\LocaleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleOutput::class)]
final class LocaleOutputTest extends TestCase
{
    public function testItReadsAFullPayload(): void
    {
        $locale = LocaleOutput::fromPayload(['code' => 'it_IT', 'name' => 'Italian (Italy)'], 'fallback');

        self::assertTrue($locale->found);
        self::assertSame('it_IT', $locale->code);
        self::assertSame('Italian (Italy)', $locale->name);
    }

    public function testItFallsBackToTheRequestedCode(): void
    {
        self::assertSame('fr_FR', LocaleOutput::fromPayload(['name' => 'French (France)'], 'fr_FR')->code);
    }

    public function testAnAbsentOrMistypedNameIsNull(): void
    {
        self::assertNull(LocaleOutput::fromPayload(['code' => 'de_DE'], 'de_DE')->name);
        self::assertNull(LocaleOutput::fromPayload(['code' => 'de_DE', 'name' => false], 'de_DE')->name);
    }

    public function testItEncodesToTheDocumentedShape(): void
    {
        self::assertSame(
            '{"found":true,"code":"it_IT","name":"Italian (Italy)"}',
            json_encode(LocaleOutput::fromPayload(['code' => 'it_IT', 'name' => 'Italian (Italy)'], 'x')),
        );
    }
}
