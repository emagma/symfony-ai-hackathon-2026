<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\WriteConfirmation;

/**
 * The text a person reads before agreeing to a write.
 */
final class DiffPreview
{
    private const int MAX_LENGTH = 120;

    private function __construct()
    {
    }

    /**
     * `- field: value`, for a creation.
     *
     * @param array<string, array{before: mixed, after: mixed}> $diff
     */
    public static function targets(string $heading, array $diff): string
    {
        $lines = [$heading, ''];
        foreach ($diff as $field => $pair) {
            $lines[] = \sprintf('- %s: %s', $field, self::format($pair['after']));
        }

        return implode("\n", $lines);
    }

    /**
     * `- field: before -> after`, for an update.
     *
     * @param array<string, array{before: mixed, after: mixed}> $diff
     */
    public static function transitions(string $heading, array $diff): string
    {
        $lines = [$heading, ''];
        foreach ($diff as $field => $pair) {
            $lines[] = \sprintf(
                '- %s: %s -> %s',
                $field,
                self::format($pair['before']),
                self::format($pair['after']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * String keys survive: without them a nested configuration is unreadable to the person confirming.
     */
    public static function format(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return '"' . self::truncate($value) . '"';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $rendered = self::format($item);
                $parts[] = \is_string($key) ? $key . ': ' . $rendered : $rendered;
            }

            return '[' . implode(', ', $parts) . ']';
        }

        return get_debug_type($value);
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_LENGTH
            ? mb_substr($value, 0, self::MAX_LENGTH - 3) . '...'
            : $value;
    }
}
