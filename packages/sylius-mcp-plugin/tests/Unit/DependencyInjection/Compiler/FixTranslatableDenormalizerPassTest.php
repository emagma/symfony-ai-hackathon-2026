<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection\Compiler;

use Emagma\SyliusMcpPlugin\DependencyInjection\Compiler\FixTranslatableDenormalizerPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixTranslatableDenormalizerPass::class)]
final class FixTranslatableDenormalizerPassTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function versions(): iterable
    {
        yield '2.0.18' => ['2.0.18.0', true];
        yield '2.1.12' => ['2.1.12.0', true];
        yield '2.1.13, fixed' => ['2.1.13.0', false];
        yield '2.2.0' => ['2.2.0.0', true];
        yield '2.2.3' => ['2.2.3.0', true];
        yield '2.2.4, fixed' => ['2.2.4.0', false];
        yield '2.3.0' => ['2.3.0.0', false];
        yield 'a branch' => ['dev-main', false];
    }

    #[DataProvider('versions')]
    public function testOnlyTheVersionsWithoutTheFixGetTheCopy(string $version, bool $needed): void
    {
        self::assertSame($needed, FixTranslatableDenormalizerPass::needsTheFix($version));
    }
}
