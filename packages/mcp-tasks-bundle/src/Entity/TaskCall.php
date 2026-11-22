<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One tool call a script asked for. Written before the call, completed after it: a row without an outcome
 * is a call the worker did not see the end of.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_task_call')]
#[ORM\Index(name: 'idx_mcp_task_call_task', columns: ['task_id', 'id'])]
class TaskCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** A short view of what the tool answered. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $outcome = null;

    /** Why the call was not made, or failed. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
        private readonly string $taskId,
        #[ORM\Column(type: 'integer')]
        private readonly int $step,
        #[ORM\Column(type: 'string', length: 128)]
        private readonly string $tool,
        #[ORM\Column(type: 'json')]
        private readonly array $arguments,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $startedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function isOver(): bool
    {
        return null !== $this->outcome || null !== $this->error;
    }

    public function succeed(string $outcome, int $durationMs): void
    {
        $this->outcome = $outcome;
        $this->durationMs = $durationMs;
    }

    public function fail(string $error, int $durationMs): void
    {
        $this->error = $error;
        $this->durationMs = $durationMs;
    }
}
