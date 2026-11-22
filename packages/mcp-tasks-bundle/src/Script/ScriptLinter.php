<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Script;

use Symfony\Component\Process\Process;

/**
 * Lints the files of one task, in place, before they are inspected and read: what it fixes is what the human
 * validates. `path` is the linter's binary, checked when the container is built (LinterPass); `command` its
 * arguments, where `{task}` is the directory of the task, the only path the configuration can hand it.
 *
 * A linter that fails throws: a task is never created with scripts the configured linter could not handle.
 *
 * @internal
 */
final readonly class ScriptLinter
{
    public const string PLACEHOLDER = '{task}';

    /**
     * @param list<string> $command
     */
    public function __construct(
        private string $path,
        private array $command,
        private float $timeout = 60.0,
    ) {
    }

    public function lint(string $taskDirectory): void
    {
        $process = new Process([$this->path, ...array_map(
            static fn (string $argument): string => self::PLACEHOLDER === $argument ? $taskDirectory : $argument,
            $this->command,
        )]);
        $process->setTimeout($this->timeout);
        $process->mustRun();
    }

    /**
     * The bundle's rules for ECS: the functions and classes of PHP written with a leading backslash.
     */
    /**
     * The arguments when emagma_mcp_tasks.linter.command is null: ECS with the bundle's rules.
     *
     * @return list<string>
     */
    public static function defaultCommand(): array
    {
        return ['check', '--fix', '--no-progress-bar', '--no-interaction', '--config=' . self::config(), self::PLACEHOLDER];
    }

    public static function config(): string
    {
        return \dirname(__DIR__, 2) . '/resources/linter/ecs.php';
    }
}
