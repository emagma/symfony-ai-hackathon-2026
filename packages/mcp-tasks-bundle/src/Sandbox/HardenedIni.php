<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The ini of the hardened process: ONE file for every task, computed by the binary that will run the scripts and
 * named after it, so that a change of binary, of the allow lists or of the memory limit builds a new one. The
 * directory a script may read is given per process (`-d open_basedir=...`).
 *
 * A proof of concept: these are PHP's own restrictions, in the worker's container and user, and a flaw of the engine
 * escapes them. In production, replace ProcessSandbox with a driver to a task server, a protected service of its own.
 *
 * @internal
 */
final readonly class HardenedIni
{
    public function __construct(
        private string $memoryLimit = '128M',
        private ?string $directory = null,
    ) {
    }

    public static function php(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    public static function bootstrap(): string
    {
        return \dirname(__DIR__, 2) . '/resources/bootstrap.php';
    }

    /**
     * The shared file, built when missing (a cache warmer builds it beforehand).
     */
    public function path(): string
    {
        $target = $this->target();
        if (!is_file($target)) {
            if (!is_dir(\dirname($target))) {
                @mkdir(\dirname($target), 0o775, true);
            }
            $process = new Process([self::php(), '-n', \dirname(__DIR__, 2) . '/resources/build-ini.php', $target, $this->memoryLimit]);
            $process->mustRun();
        }

        return $target;
    }

    /**
     * The command line of the process that plays a step of the task in this directory.
     *
     * @return list<string>
     */
    public function command(string $taskDirectory): array
    {
        return [
            self::php(), '-n', '-c', $this->path(),
            '-d', 'open_basedir=' . rtrim($taskDirectory, '/') . \PATH_SEPARATOR . self::bootstrap(),
            self::bootstrap(),
        ];
    }

    private function target(): string
    {
        $resources = \dirname(__DIR__, 2) . '/resources';
        $fingerprint = hash('xxh128', implode('|', [
            self::php(),
            \PHP_VERSION,
            $this->memoryLimit,
            (string) @filemtime(self::php()),
            (string) file_get_contents($resources . '/allowed-functions.php'),
            (string) file_get_contents($resources . '/allowed-classes.php'),
            (string) file_get_contents($resources . '/build-ini.php'),
        ]));

        return rtrim($this->directory ?? sys_get_temp_dir() . '/mcp-tasks', '/') . '/hardened-' . \PHP_VERSION . '-' . substr($fingerprint, 0, 12) . '.ini';
    }
}
