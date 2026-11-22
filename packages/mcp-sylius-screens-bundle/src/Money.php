<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle;

/**
 * Amounts as a person types them, to and from the minor units the Admin API speaks.
 *
 * @internal
 */
final class Money
{
    private const array ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const array THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private function __construct()
    {
    }

    public static function decimals(?string $currency): int
    {
        return match (true) {
            \in_array($currency, self::ZERO_DECIMALS, true) => 0,
            \in_array($currency, self::THREE_DECIMALS, true) => 3,
            default => 2,
        };
    }

    public static function format(int $minor, ?string $currency): string
    {
        $decimals = self::decimals($currency);

        return number_format($minor / 10 ** $decimals, $decimals, '.', '');
    }

    /**
     * "12.5", "12,50", " 12 " are amounts; "-3", "12.345" (in a two-decimal currency) and "abc" are not.
     */
    public static function parse(string $typed, ?string $currency): ?int
    {
        $decimals = self::decimals($currency);
        $typed = str_replace(',', '.', trim($typed));
        $pattern = 0 === $decimals ? '/^\d{1,9}$/' : '/^\d{1,9}(\.\d{1,' . $decimals . '})?$/';
        if (1 !== preg_match($pattern, $typed)) {
            return null;
        }
        [$units, $fraction] = explode('.', $typed) + [1 => ''];

        return (int) $units * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');
    }
}
