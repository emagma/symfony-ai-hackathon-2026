<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Gate;

use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShopExtensions::class)]
final class ShopExtensionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function values(): iterable
    {
        yield 'empty' => ['', []];
        yield 'blank' => ['  ', []];
        yield 'commas only' => [' , ,', []];
        yield 'one' => ['sylius_cms', ['sylius_cms']];
        yield 'trimmed' => [' sylius_cms ,', ['sylius_cms']];
        yield 'duplicated' => ['sylius_cms,sylius_cms', ['sylius_cms']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('values')]
    public function testParsesTheCommaSeparatedList(string $value, array $expected): void
    {
        $extensions = new ShopExtensions($value, ['sylius_cms']);

        self::assertSame($expected, $extensions->active());
        self::assertSame([] !== $expected, $extensions->isActive('sylius_cms'));
    }

    public function testTheDefaultActivatesNothing(): void
    {
        self::assertSame([], (new ShopExtensions())->active());
    }

    public function testAnUnknownNameFailsLoudlyNamingTheValueAndTheKnownList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown shop extension "cms" (value: "sylius_cms, cms"). Known extensions: sylius_cms.');

        new ShopExtensions('sylius_cms, cms', ['sylius_cms']);
    }

    public function testNamesAreCaseSensitive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ShopExtensions('Sylius_CMS', ['sylius_cms']);
    }
}
