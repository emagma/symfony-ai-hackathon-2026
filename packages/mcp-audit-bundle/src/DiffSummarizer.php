<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle;

/**
 * Deliberately not the core's DiffPreview: that one is a multi-line confirmation, this one a column of a listing.
 *
 * @internal
 */
final class DiffSummarizer
{
    private const int MAX_LENGTH = 60;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $diff
     */
    public static function summarize(array $diff): string
    {
        $parts = [];
        foreach ($diff as $field => $pair) {
            if (!\is_array($pair) || !\array_key_exists('after', $pair)) {
                continue;
            }
            $parts[] = \sprintf(
                '%s: %s -> %s',
                $field,
                self::format($pair['before'] ?? null),
                self::format($pair['after'] ?? null),
            );
        }

        return [] === $parts ? '(no diff)' : implode('; ', $parts);
    }

    private static function format(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            $trimmed = mb_strlen($value) > self::MAX_LENGTH
                ? mb_substr($value, 0, self::MAX_LENGTH - 3) . '...'
                : $value;

            return '"' . $trimmed . '"';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_array($value)) {
            return '[' . implode(', ', array_map(self::format(...), $value)) . ']';
        }

        return get_debug_type($value);
    }
}
