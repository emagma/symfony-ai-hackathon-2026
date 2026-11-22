<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared\Batch;

/**
 * The text a human reads before approving a batch. Leads with the counts, not N diffs:
 * "3 creations" is what they need to see when they expected updates and a code was mistyped.
 *
 * @internal
 */
final class BatchSummary
{
    private const int SAMPLE = 15;

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, array{before: mixed, after: mixed}>> $creates code => diff
     * @param array<string, array<string, array{before: mixed, after: mixed}>> $updates code => diff
     * @param array<string, array<string, mixed>>                             $skipped code => the per-item result that will not be written
     * @param (\Closure(string, array<string, array{before: mixed, after: mixed}>): list<string>)|null $detail
     *                                                                                                       extra lines under an item (a resource whose field names alone say too little: CMS content elements); none by default
     */
    public static function upsert(string $label, array $creates, array $updates, array $skipped, ?\Closure $detail = null): string
    {
        $lines = [\sprintf('Batch on %s: %d creation(s), %d update(s)', $label, \count($creates), \count($updates)), ''];

        if ([] !== $creates) {
            $lines[] = \sprintf('%d creation(s):', \count($creates));
            foreach (self::sample(array_keys($creates)) as $code) {
                $lines[] = '  + ' . $code;
                array_push($lines, ...self::detail($detail, (string) $code, $creates[$code]));
            }
            $lines[] = self::more(\count($creates));
        }
        if ([] !== $updates) {
            $lines[] = \sprintf('%d update(s):', \count($updates));
            foreach (self::sample(array_keys($updates)) as $code) {
                $lines[] = '  ~ ' . $code . ' (' . implode(', ', array_keys($updates[$code])) . ')';
                array_push($lines, ...self::detail($detail, (string) $code, $updates[$code]));
            }
            $lines[] = self::more(\count($updates));
        }
        $lines[] = self::skipped($skipped);

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param array<string, string|null>          $deletes code => human name when known
     * @param array<string, array<string, mixed>> $skipped
     */
    public static function delete(string $label, array $deletes, array $skipped): string
    {
        $lines = [\sprintf('Batch deletion of %d %s', \count($deletes), $label), ''];
        foreach (self::sample(array_keys($deletes)) as $code) {
            $name = $deletes[$code];
            $lines[] = '  - ' . $code . (null !== $name && '' !== $name ? ' (' . $name . ')' : '');
        }
        $lines[] = self::more(\count($deletes));
        $lines[] = '';
        $lines[] = 'Deletions cannot be reverted.';
        $lines[] = self::skipped($skipped);

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param (\Closure(string, array<string, array{before: mixed, after: mixed}>): list<string>)|null $detail
     * @param array<string, array{before: mixed, after: mixed}>                                         $diff
     *
     * @return list<string>
     */
    private static function detail(?\Closure $detail, string $code, array $diff): array
    {
        if (null === $detail) {
            return [];
        }

        return array_map(static fn (string $line): string => '      ' . $line, $detail($code, $diff));
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function sample(array $codes): array
    {
        return \array_slice($codes, 0, self::SAMPLE);
    }

    private static function more(int $count): string
    {
        return $count > self::SAMPLE ? \sprintf('  … and %d more', $count - self::SAMPLE) : '';
    }

    /**
     * @param array<string, array<string, mixed>> $skipped
     */
    private static function skipped(array $skipped): string
    {
        if ([] === $skipped) {
            return '';
        }
        $byReason = [];
        foreach ($skipped as $result) {
            $reason = \is_string($result['reason'] ?? null) ? $result['reason'] : (\is_string($result['action'] ?? null) ? $result['action'] : 'skipped');
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }
        $parts = [];
        foreach ($byReason as $reason => $count) {
            $parts[] = $reason . ' ×' . $count;
        }

        return "\n" . 'Not written: ' . implode(', ', $parts);
    }
}
