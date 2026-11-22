<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Tests\Unit;

use Emagma\McpSyliusScreensBundle\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[TestWith(['12.5', 'USD', 1250])]
    #[TestWith(['12,50', 'EUR', 1250])]
    #[TestWith([' 12 ', 'EUR', 1200])]
    #[TestWith(['0.05', 'EUR', 5])]
    #[TestWith(['1200', 'JPY', 1200])]
    #[TestWith(['1.234', 'KWD', 1234])]
    #[TestWith(['12.345', 'EUR', null])]
    #[TestWith(['12.5', 'JPY', null])]
    #[TestWith(['-3', 'EUR', null])]
    #[TestWith(['abc', 'EUR', null])]
    #[TestWith(['', 'EUR', null])]
    public function testWhatAPersonTypesBecomesMinorUnitsOrNothing(string $typed, string $currency, ?int $minor): void
    {
        self::assertSame($minor, Money::parse($typed, $currency));
    }

    public function testMinorUnitsAreShownWithTheDecimalsOfTheCurrency(): void
    {
        self::assertSame('99.05', Money::format(9905, 'USD'));
        self::assertSame('1200', Money::format(1200, 'JPY'));
        self::assertSame('1.234', Money::format(1234, 'KWD'));
        self::assertSame('5.00', Money::format(500, null));
    }
}
