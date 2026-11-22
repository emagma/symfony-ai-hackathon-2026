<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Command;

use Emagma\McpSyliusBundle\Tool\Product\GetProducts;
use Emagma\McpSyliusBundle\Tool\Product\SearchProducts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\TraceableHttpClient;

/**
 * Plays an autonomous agent's scenario against the shop and reports the HTTP calls it took.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:bench:agent',
    description: 'Run the "agent autonomous multi-step" scenario against the Sylius admin API and dump the captured trace.',
)]
final class AgentScenarioCommand extends Command
{
    private const int FAN_OUT_SIZE = 20;

    private const int DUPLICATE_REPLAY_SIZE = 5;

    public function __construct(
        private readonly SearchProducts $searchProducts,
        private readonly GetProducts $getProducts,
        private readonly TraceableHttpClient $traceableClient,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Repeat the full scenario N times', '1')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output JSON file path (defaults to var/bench/mcp-<ts>.json)')
            ->addOption('reset-jwt', null, InputOption::VALUE_NONE, 'Force a fresh JWT before starting (simulate cold session)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $iterations = max(1, (int) $input->getOption('iterations'));

        // Drops the traces of the container boot.
        $this->traceableClient->reset();

        $perToolLatencies = ['search_products' => [], 'get_products' => []];
        $wallStart = microtime(true);

        for ($iteration = 1; $iteration <= $iterations; ++$iteration) {
            $io->section(sprintf('Iteration %d / %d', $iteration, $iterations));

            $page1 = $this->time($perToolLatencies['search_products'], fn () => ($this->searchProducts)(itemsPerPage: 50));
            $codesPage1 = array_column($page1['items'], 'code');
            $io->writeln(sprintf('  search_products(p=1, ipp=50) → %d items, total=%d', \count($codesPage1), $page1['total']));

            $fanOut1 = \array_slice($codesPage1, 0, self::FAN_OUT_SIZE);
            $this->runFanOut($fanOut1, $perToolLatencies);
            $io->writeln(sprintf('  get_products × %d (fan-out 1)', \count($fanOut1)));

            $codesPage2 = [];
            if ($page1['hasMore']) {
                $page2 = $this->time($perToolLatencies['search_products'], fn () => ($this->searchProducts)(itemsPerPage: 50, page: 2));
                $codesPage2 = array_column($page2['items'], 'code');
                $io->writeln(sprintf('  search_products(p=2, ipp=50) → %d items', \count($codesPage2)));
            } else {
                $io->writeln('  search_products(p=2) skipped (no more pages)');
            }

            $fanOut2 = \array_slice($codesPage2, 0, self::FAN_OUT_SIZE);
            $this->runFanOut($fanOut2, $perToolLatencies);
            $io->writeln(sprintf('  get_products × %d (fan-out 2)', \count($fanOut2)));

            $this->time($perToolLatencies['search_products'], fn () => ($this->searchProducts)(name: 'a'));
            $io->writeln('  search_products(name=a) (search)');

            $duplicates = \array_slice($codesPage1, 0, self::DUPLICATE_REPLAY_SIZE);
            $this->runFanOut($duplicates, $perToolLatencies);
            $io->writeln(sprintf('  get_products × %d (duplicates)', \count($duplicates)));
        }

        $wallClock = microtime(true) - $wallStart;
        $traces = $this->traceableClient->getTracedRequests();

        $jsonPath = $this->resolveOutputPath((string) ($input->getOption('output') ?? ''));
        $this->dumpJson($jsonPath, $wallClock, $perToolLatencies, $traces, $iterations);

        $mdPath = preg_replace('/\.json$/', '.md', $jsonPath) ?? $jsonPath . '.md';
        $this->dumpMarkdown($mdPath, $wallClock, $perToolLatencies, $traces, $iterations);

        $io->success(sprintf(
            'Scenario done - %d HTTP calls (%d JWT) in %.3fs',
            \count($traces),
            $this->countJwtCalls($traces),
            $wallClock,
        ));
        $io->writeln(sprintf('  JSON trace : %s', $jsonPath));
        $io->writeln(sprintf('  Markdown   : %s', $mdPath));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>                                              $codes
     * @param array{search_products: list<float>, get_products: list<float>} $latencies
     */
    private function runFanOut(array $codes, array &$latencies): void
    {
        foreach ($codes as $code) {
            $this->time($latencies['get_products'], fn () => ($this->getProducts)([$code]));
        }
    }

    /**
     * @param list<float> $sink
     *
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    private function time(array &$sink, callable $fn): mixed
    {
        $t0 = microtime(true);
        $result = $fn();
        $sink[] = microtime(true) - $t0;

        return $result;
    }

    /**
     * @param iterable<int, array{method: string, url: string, options: array<string, mixed>, info: callable, content: callable}> $traces
     */
    private function countJwtCalls(iterable $traces): int
    {
        $count = 0;
        foreach ($traces as $trace) {
            if (str_contains((string) $trace['url'], 'administrators/token')) {
                ++$count;
            }
        }

        return $count;
    }

    private function resolveOutputPath(string $userPath): string
    {
        if ('' !== $userPath) {
            return $userPath;
        }
        $dir = $this->projectDir . '/var/bench';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create bench output directory: %s', $dir));
        }

        return sprintf('%s/mcp-%s.json', $dir, (new \DateTimeImmutable())->format('Y-m-d_H-i-s'));
    }

    /**
     * @param array{search_products: list<float>, get_products: list<float>} $perToolLatencies
     * @param iterable<int, array{method: string, url: string, options: array<string, mixed>, info: array<string, mixed>, content: mixed}> $traces
     */
    private function dumpJson(string $path, float $wallClock, array $perToolLatencies, iterable $traces, int $iterations): void
    {
        $calls = [];
        foreach ($traces as $trace) {
            $info = $trace['info'];
            $calls[] = [
                'method' => $trace['method'],
                'url' => (string) $trace['url'],
                'http_version' => $info['http_version'] ?? null,
                'response_status' => $info['response_status_code'] ?? $info['http_code'] ?? null,
                'total_time' => $info['total_time'] ?? null,
                'connect_time' => $info['connect_time'] ?? null,
                'namelookup_time' => $info['namelookup_time'] ?? null,
                'pretransfer_time' => $info['pretransfer_time'] ?? null,
                'starttransfer_time' => $info['starttransfer_time'] ?? null,
                'size_download' => $info['size_download'] ?? null,
                'size_upload' => $info['size_upload'] ?? null,
                'primary_ip' => $info['primary_ip'] ?? null,
                'primary_port' => $info['primary_port'] ?? null,
                'response_headers' => $this->extractInterestingHeaders($info['response_headers'] ?? []),
                'is_jwt' => str_contains((string) $trace['url'], 'administrators/token'),
            ];
        }

        $payload = [
            'meta' => [
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'iterations' => $iterations,
                'wall_clock_s' => $wallClock,
            ],
            'per_tool_latencies_s' => $perToolLatencies,
            'calls' => $calls,
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        file_put_contents($path, $json);
    }

    /**
     * @param array{search_products: list<float>, get_products: list<float>} $perToolLatencies
     * @param iterable<int, array{method: string, url: string, options: array<string, mixed>, info: array<string, mixed>, content: mixed}> $traces
     */
    private function dumpMarkdown(string $path, float $wallClock, array $perToolLatencies, iterable $traces, int $iterations): void
    {
        $callsList = [];
        foreach ($traces as $trace) {
            $callsList[] = $trace;
        }
        $totalCalls = \count($callsList);
        $jwtCalls = $this->countJwtCalls($callsList);
        $businessCalls = $totalCalls - $jwtCalls;

        $httpVersions = [];
        $statusCodes = [];
        $withEtag = 0;
        $withEncoding = [];
        $bytesDown = 0;
        $sumTotalTime = 0.0;
        $urls = [];
        $listDownloadBytes = [];
        $getDownloadBytes = [];

        foreach ($callsList as $trace) {
            $info = $trace['info'];
            $version = (string) ($info['http_version'] ?? '?');
            $httpVersions[$version] = ($httpVersions[$version] ?? 0) + 1;
            $status = (int) ($info['response_status_code'] ?? $info['http_code'] ?? 0);
            $statusCodes[$status] = ($statusCodes[$status] ?? 0) + 1;
            $headers = $this->extractInterestingHeaders($info['response_headers'] ?? []);
            if (isset($headers['etag'])) {
                ++$withEtag;
            }
            if (isset($headers['content_encoding'])) {
                $enc = $headers['content_encoding'];
                $withEncoding[$enc] = ($withEncoding[$enc] ?? 0) + 1;
            }
            $size = (int) ($info['size_download'] ?? 0);
            $bytesDown += $size;
            $sumTotalTime += (float) ($info['total_time'] ?? 0);
            $urls[] = (string) $trace['url'];

            $url = (string) $trace['url'];
            if (str_contains($url, 'administrators/token')) {
                continue;
            }
            if (str_contains($url, 'products/')) {
                $getDownloadBytes[] = $size;
            } elseif (str_contains($url, 'products')) {
                $listDownloadBytes[] = $size;
            }
        }

        $uniqueUrls = \count(array_unique($urls));
        $duplicates = $totalCalls - $uniqueUrls;
        $gap = $wallClock - $sumTotalTime;

        $lines = [];
        $lines[] = '# MCP Admin API Bench - Agent Autonomous Scenario';
        $lines[] = '';
        $lines[] = sprintf('Generated: %s', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $lines[] = sprintf('Iterations: %d', $iterations);
        $lines[] = '';
        $lines[] = '## Top-line metrics';
        $lines[] = '';
        $lines[] = sprintf('- **Wall-clock**: %.3fs', $wallClock);
        $lines[] = sprintf('- **Total HTTP calls**: %d (%d business + %d JWT)', $totalCalls, $businessCalls, $jwtCalls);
        $lines[] = sprintf('- **Σ per-call total_time**: %.3fs', $sumTotalTime);
        $lines[] = sprintf('- **Gap (wall-clock − Σ)**: %.3fs → %s', $gap, abs($gap) < 0.1 ? 'strictly sequential (no concurrency)' : 'some overlap');
        $lines[] = sprintf('- **Bytes downloaded**: %s (%.1f KB)', number_format($bytesDown), $bytesDown / 1024);
        $lines[] = sprintf('- **Unique URLs**: %d / %d → %d duplicates', $uniqueUrls, $totalCalls, $duplicates);
        $lines[] = '';

        $lines[] = '## Transport';
        $lines[] = '';
        $lines[] = '- HTTP version distribution:';
        foreach ($httpVersions as $v => $n) {
            $lines[] = sprintf('  - HTTP/%s: %d (%.0f%%)', $v, $n, 100 * $n / $totalCalls);
        }
        $lines[] = '- Status codes:';
        foreach ($statusCodes as $code => $n) {
            $lines[] = sprintf('  - %d: %d', $code, $n);
        }
        $lines[] = sprintf('- Calls with ETag in response: %d / %d (%.0f%%)', $withEtag, $totalCalls, 100 * $withEtag / max(1, $totalCalls));
        $lines[] = '- Content-Encoding distribution:';
        if ([] === $withEncoding) {
            $lines[] = '  - (none - bodies are uncompressed)';
        } else {
            foreach ($withEncoding as $enc => $n) {
                $lines[] = sprintf('  - %s: %d', $enc, $n);
            }
        }
        $lines[] = '';

        $lines[] = '## Per-tool latency (seconds)';
        $lines[] = '';
        $lines[] = '| Tool | calls | p50 | p95 | min | max | Σ |';
        $lines[] = '|---|---|---|---|---|---|---|';
        foreach ($perToolLatencies as $tool => $values) {
            if ([] === $values) {
                $lines[] = sprintf('| `%s` | 0 | - | - | - | - | - |', $tool);

                continue;
            }
            sort($values);
            $n = \count($values);
            $p50 = $values[(int) floor($n * 0.5)];
            $p95 = $values[min($n - 1, (int) floor($n * 0.95))];
            $lines[] = sprintf(
                '| `%s` | %d | %.3f | %.3f | %.3f | %.3f | %.3f |',
                $tool,
                $n,
                $p50,
                $p95,
                $values[0],
                $values[$n - 1],
                array_sum($values),
            );
        }
        $lines[] = '';

        if ([] !== $listDownloadBytes) {
            $lines[] = sprintf('- `search_products` avg payload: %.1f KB', array_sum($listDownloadBytes) / \count($listDownloadBytes) / 1024);
        }
        if ([] !== $getDownloadBytes) {
            $lines[] = sprintf('- `get_products` avg payload: %.1f KB', array_sum($getDownloadBytes) / \count($getDownloadBytes) / 1024);
        }
        $lines[] = '';

        $lines[] = '## Decision matrix readout';
        $lines[] = '';
        if ($jwtCalls > 1) {
            $lines[] = sprintf('- ✅ **JWT cache**: %d auth calls per session → high ROI', $jwtCalls);
        } elseif ($jwtCalls === 1) {
            $lines[] = '- ⚠️  **JWT cache**: 1 auth per session - small absolute win but cheap to implement';
        }
        if ($duplicates > 0.1 * $totalCalls) {
            $lines[] = sprintf('- ✅ **MCP-level PSR-6 cache**: %d duplicate URLs (%.0f%%) → high ROI', $duplicates, 100 * $duplicates / $totalCalls);
        } else {
            $lines[] = sprintf('- ⚠️  **MCP-level cache**: only %d duplicates (%.0f%%) - marginal', $duplicates, 100 * $duplicates / $totalCalls);
        }
        if (isset($httpVersions['1.1']) && $httpVersions['1.1'] > $totalCalls / 2) {
            $lines[] = '- ✅ **HTTP/2**: server is downgrading to 1.1 → check Caddy/Frankenphp config';
        } else {
            $lines[] = '- ⛔ **HTTP/2**: already active, no work needed';
        }
        if ($withEtag === 0) {
            $lines[] = '- ⛔ **ETag/304**: no ETag emitted by server - would need API Platform config first';
        } else {
            $lines[] = sprintf('- ✅ **ETag/304 via CachingHttpClient**: %d/%d responses ETagged → directly cacheable', $withEtag, $totalCalls);
        }
        if (abs($gap) < 0.5) {
            $lines[] = sprintf('- ✅ **Concurrence via stream() / batch tool**: Σ=%.3fs ≈ wall-clock → fan-out N parallel cuts time by ~N', $sumTotalTime);
        }
        if ([] === $withEncoding) {
            $lines[] = '- ✅ **Force Accept-Encoding: gzip**: no compression negotiated';
        } else {
            $lines[] = sprintf('- ⛔ **Compression**: already active (%s)', implode(',', array_keys($withEncoding)));
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string>|array<string, list<string>> $headers
     *
     * @return array{etag?: string, cache_control?: string, content_encoding?: string, content_type?: string, content_length?: string}
     */
    private function extractInterestingHeaders(array $headers): array
    {
        $wanted = ['etag', 'cache-control', 'content-encoding', 'content-type', 'content-length'];
        $out = [];

        // CurlHttpClient gives a list of "Key: value" strings, other clients a map.
        if (isset($headers[0])) {
            foreach ($headers as $line) {
                if (!\is_string($line)) {
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (2 !== \count($parts)) {
                    continue;
                }
                $key = strtolower(trim($parts[0]));
                if (\in_array($key, $wanted, true)) {
                    $out[str_replace('-', '_', $key)] = trim($parts[1]);
                }
            }

            return $out;
        }

        foreach ($wanted as $key) {
            if (isset($headers[$key])) {
                $value = $headers[$key];
                $out[str_replace('-', '_', $key)] = \is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return $out;
    }
}
