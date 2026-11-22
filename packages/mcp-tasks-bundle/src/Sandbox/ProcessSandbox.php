<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * `php -n -c <hardened>.ini -d open_basedir=<task> bootstrap.php` in the directory of the task, with an empty environment: no shared
 * extension, a whitelist of functions, no file outside the task, no variable of the worker.
 */
final readonly class ProcessSandbox implements SandboxInterface
{
    public function __construct(
        private HardenedIni $ini = new HardenedIni(),
    ) {
    }

    public function open(string $taskDirectory, float $timeout): SandboxSessionInterface
    {
        $input = new InputStream();
        // Process merges what it is given with the worker's environment: every inherited variable is unset.
        // The names as strings: Symfony 8.1 types the environment array<string, ...>.
        $environment = array_fill_keys(array_map(strval(...), array_keys(getenv() + $_ENV + $_SERVER)), false);

        $process = new Process(
            $this->ini->command($taskDirectory),
            $taskDirectory,
            $environment,
            $input,
            $timeout,
        );
        $process->start();

        return new class($process, $input) implements SandboxSessionInterface {
            private string $buffer = '';

            private bool $consumed = false;

            /** @var \Generator<string, string>|null */
            private ?\Generator $output = null;

            public function __construct(
                private readonly Process $process,
                private readonly InputStream $input,
            ) {
            }

            public function send(array $line): void
            {
                $this->input->write(json_encode($line, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }

            public function receive(): ?array
            {
                $this->output ??= $this->process->getIterator(Process::ITER_SKIP_ERR);

                try {
                    while (!str_contains($this->buffer, "\n")) {
                        // Advancing waits for more output: only once the chunk in hand held no whole line.
                        if ($this->consumed) {
                            $this->output->next();
                        }
                        if (!$this->output->valid()) {
                            return null;
                        }
                        $this->buffer .= $this->output->current();
                        $this->consumed = true;
                    }
                } catch (ProcessTimedOutException $exception) {
                    throw new SandboxTimeout($exception->getMessage(), previous: $exception);
                }

                [$line, $this->buffer] = explode("\n", $this->buffer, 2);
                $decoded = json_decode($line, true);

                // Anything that is not a line of the dialogue ends it: the script wrote on stdout by itself.
                if (!\is_array($decoded)) {
                    return ['ok' => false, 'error' => 'The script wrote outside the dialogue: ' . mb_substr($line, 0, 200)];
                }
                $named = [];
                foreach ($decoded as $name => $value) {
                    $named[(string) $name] = $value;
                }

                return $named;
            }

            public function errorOutput(): string
            {
                return trim($this->process->getErrorOutput());
            }

            public function close(): void
            {
                $this->input->close();
                $this->process->stop(1.0);
            }
        };
    }
}
