<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\TestCase;

/**
 * Every answer of a write tool carries `ok`, no read tool's does (`found: false` is not a failure).
 * Static, on every `return [` literal: a forgotten `ok` hides on the path no dynamic test thought of.
 */
abstract class ToolResultEnvelopeTestCase extends TestCase
{
    abstract protected static function tools(): ToolSources;

    protected static function minimumChecked(): int
    {
        return 1;
    }

    private const string VERBS = 'updated|created|deleted|refunded|completed|shipped|cancelled|accepted|rejected|archived|restored|reverted|sent|removed|added|generated|found';

    public function testEveryWriteToolResultLiteralCarriesOk(): void
    {
        $missing = [];
        $count = 0;

        foreach ($this->toolFiles() as $relative => $source) {
            if ($this->isRead($relative)) {
                continue;
            }
            foreach ($this->returnLiterals($source) as $line => $literal) {
                ++$count;
                if (!str_contains($literal, "'ok' =>")) {
                    $missing[] = $relative . ':' . $line;
                }
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $count, 'literal discovery looks broken');
        self::assertSame([], $missing, "These write results have no ok key:\n" . implode("\n", $missing));
    }

    public function testNoReadToolResultLiteralCarriesOk(): void
    {
        $offenders = [];

        foreach ($this->toolFiles() as $relative => $source) {
            if (!$this->isRead($relative)) {
                continue;
            }
            foreach ($this->returnLiterals($source) as $line => $literal) {
                if (str_contains($literal, "'ok' =>")) {
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        self::assertSame([], $offenders, "found: false is not a failure; these read results carry ok:\n" . implode("\n", $offenders));
    }

    private function isRead(string $relative): bool
    {
        $short = basename($relative, '.php');

        return str_starts_with($short, 'Get') || str_starts_with($short, 'List') || str_starts_with($short, 'Search') || 'Order/OrderSubCollectionTrait.php' === $relative;
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    private function toolFiles(): iterable
    {
        foreach (static::tools()->files(null, ['Shared']) as $relative => $file) {
            yield $relative => (string) file_get_contents($file->getPathname());
        }
    }

    /**
     * @return iterable<int, string> line => literal
     */
    private function returnLiterals(string $source): iterable
    {
        $offset = 0;
        while (false !== ($position = strpos($source, 'return [', $offset))) {
            $i = $position + \strlen('return [');
            $depth = 1;
            while ($depth > 0 && $i < \strlen($source)) {
                $c = $source[$i];
                if ('[' === $c || '(' === $c) {
                    ++$depth;
                } elseif (']' === $c || ')' === $c) {
                    --$depth;
                }
                ++$i;
            }
            $literal = substr($source, $position, $i - $position);
            $offset = $i;
            // An answer starts with its verb, ok or reason; a `[...] + $refusal` merge gets ok from the refusal.
            if (1 === preg_match('/^return \\[\\s*\'(?:ok|reason|' . self::VERBS . ')\' =>/', $literal) && !str_contains(substr($source, $i, 12), '+ $refusal')) {
                yield substr_count(substr($source, 0, $position), "\n") + 1 => $literal;
            }
        }
    }
}
