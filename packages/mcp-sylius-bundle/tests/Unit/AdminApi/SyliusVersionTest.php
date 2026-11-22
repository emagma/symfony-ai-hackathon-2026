<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyliusVersion::class)]
final class SyliusVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function versions(): iterable
    {
        yield 'a 2.x line' => ['2.3', '2.3', '2.3'];
        yield 'with a patch' => ['2.2.9', '2.2.9', '2.2'];
        yield 'a zero patch is the line' => ['2.0.0', '2.0', '2.0'];
        yield '1.14' => ['1.14', '1.14', '1.14'];
        yield 'trimmed' => [' 2.1 ', '2.1', '2.1'];
        yield 'a line no bundle may serve: not its business' => ['3.0', '3.0', '3.0'];
    }

    #[DataProvider('versions')]
    public function testParsesALineWithAnOptionalPatch(string $value, string $string, string $line): void
    {
        $version = new SyliusVersion($value);

        self::assertSame($string, (string) $version);
        self::assertSame($line, $version->line());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'a major only' => ['2'];
        yield 'a v prefix' => ['v2.3'];
        yield 'a pre-release' => ['2.3.0-RC.1'];
    }

    #[DataProvider('malformed')]
    public function testWhatIsNotAVersionFailsLoudly(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a Sylius version');

        new SyliusVersion($value);
    }

    public function testComparesMajorMinorAndPatch(): void
    {
        self::assertTrue((new SyliusVersion('2.2.9'))->isAtLeast(new SyliusVersion('2.2')));
        self::assertTrue((new SyliusVersion('2.0'))->isAtLeast(new SyliusVersion('1.14')));
        self::assertFalse((new SyliusVersion('1.14'))->isAtLeast(new SyliusVersion('2.0')));
        self::assertFalse((new SyliusVersion('2.2'))->isAtLeast(new SyliusVersion('2.2.9')));
    }

    public function testAFeatureIsAvailableFromItsVersionOn(): void
    {
        self::assertFalse(ApiFeature::PaymentRefund->isAvailableOn(new SyliusVersion('1.14')));
        self::assertTrue(ApiFeature::PaymentRefund->isAvailableOn(new SyliusVersion('2.0')));
        self::assertTrue(ApiFeature::PaymentRefund->isAvailableOn(new SyliusVersion('2.3')));
    }
}
