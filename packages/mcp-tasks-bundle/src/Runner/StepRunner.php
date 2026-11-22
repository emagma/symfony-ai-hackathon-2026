<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Runner;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Entity\TaskCall;
use Emagma\McpTasksBundle\Sandbox\SandboxInterface;
use Emagma\McpTasksBundle\Sandbox\SandboxTimeout;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Psr\Clock\ClockInterface;

/**
 * Plays one step: starts its script in the sandbox and answers each tool call it asks for. The script
 * holds no key: the tool is called here, in the worker, when it is in the manifest of the task.
 */
final readonly class StepRunner
{
    private const int OUTCOME_LENGTH = 1000;

    public function __construct(
        private SandboxInterface $sandbox,
        private ToolInvokerInterface $tools,
        private TaskStoreInterface $store,
        private TaskFiles $files,
        private ClockInterface $clock,
        private TaskLimits $limits = new TaskLimits(),
    ) {
    }

    public function run(Task $task, int $step): StepResult
    {
        $session = $this->sandbox->open($this->files->directory($task->getId()), $this->limits->stepTimeout);
        // What the worker itself refused: the script may catch the exception and say something else.
        $refusal = null;

        try {
            // Only bootstrap.php knows it: a last line without it was written by the script itself.
            $seal = bin2hex(random_bytes(16));
            $session->send(['play' => TaskFiles::step($step), 'seal' => $seal]);
            while (true) {
                $line = $session->receive();
                if (null === $line) {
                    return StepResult::failed('The script stopped without an answer. ' . mb_substr($session->errorOutput(), -500));
                }
                if (\is_string($line['call'] ?? null)) {
                    $answer = $this->answer($task, $step, $line['call'], self::arguments($line['args'] ?? null), $refusal);
                    $session->send(['id' => $line['id'] ?? null] + $answer);

                    continue;
                }
                if (!hash_equals($seal, \is_string($line['seal'] ?? null) ? $line['seal'] : '')) {
                    return StepResult::failed('The script wrote outside the dialogue.');
                }
                if (true === ($line['ok'] ?? null) && null === $refusal) {
                    return StepResult::done();
                }

                return StepResult::failed($refusal ?? (\is_string($line['error'] ?? null) ? $line['error'] : 'The script failed without a message.'));
            }
        } catch (SandboxTimeout) {
            return StepResult::failed(\sprintf('The step lasted more than %d seconds.', (int) $this->limits->stepTimeout));
        } finally {
            $session->close();
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{result: array<string, mixed>}|array{error: string}
     */
    private function answer(Task $task, int $step, string $tool, array $arguments, ?string &$refusal): array
    {
        // Read again at each call: a cancellation comes from another process.
        if (TaskStatus::Running !== $this->store->status($task->getId())) {
            return ['error' => $refusal = 'The task was cancelled.'];
        }
        if (!\in_array($tool, $task->getManifest(), true)) {
            return ['error' => $refusal = \sprintf('The tool "%s" is not in the manifest of the task.', $tool)];
        }
        if ($task->getCalls() >= $this->limits->maxCalls) {
            return ['error' => $refusal = \sprintf('The task reached its limit of %d tool calls.', $this->limits->maxCalls)];
        }

        $started = $this->clock->now();
        $call = new TaskCall($task->getId(), $step, $tool, $arguments, $started);
        $task->countCall($tool, $started);
        // Before the call: a row without an outcome is a call nobody saw the end of.
        $this->store->addCall($call);

        try {
            $result = $this->tools->invoke($tool, $arguments);
        } catch (ToolCallFailed $failure) {
            $call->fail($failure->getMessage(), $this->elapsed($started));
            $this->store->save();

            return ['error' => $failure->getMessage()];
        }

        // A read that refuses says `reason` without `ok`: a failure all the same.
        if (!\array_key_exists('ok', $result) && \is_string($result['reason'] ?? null)) {
            $call->fail($result['reason'], $this->elapsed($started));
            $this->store->save();

            return ['error' => $result['reason']];
        }

        $outcome = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $call->succeed(mb_substr(\is_string($outcome) ? $outcome : '', 0, self::OUTCOME_LENGTH), $this->elapsed($started));
        $this->store->save();

        return ['result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private static function arguments(mixed $arguments): array
    {
        $named = [];
        foreach (\is_array($arguments) ? $arguments : [] as $name => $value) {
            $named[(string) $name] = $value;
        }

        return $named;
    }

    private function elapsed(\DateTimeImmutable $since): int
    {
        return (int) round(((float) $this->clock->now()->format('U.u') - (float) $since->format('U.u')) * 1000);
    }
}
