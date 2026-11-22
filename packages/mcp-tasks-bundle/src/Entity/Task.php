<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Emagma\McpTasksBundle\TaskStatus;

/**
 * A long treatment a user asked for: PHP scripts a model wrote, validated by that user on their reading,
 * played step by step, by the orchestrator the bundle is configured with, with that user's rights.
 *
 * Not final: Doctrine builds its proxies by inheritance. `createdAt` is not readonly: the store refreshes
 * the task, and a refresh hydrates a new object that Doctrine refuses to put in a readonly property.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_task')]
#[ORM\Index(name: 'idx_mcp_task_owner', columns: ['owner', 'created_at'])]
#[ORM\Index(name: 'idx_mcp_task_status', columns: ['status'])]
class Task
{
    #[ORM\Column(type: 'string', length: 32)]
    private string $status = TaskStatus::Draft->value;

    /** Index of the step being played, or of the one the task stopped in. */
    #[ORM\Column(name: 'current_step', type: 'integer')]
    private int $currentStep = 0;

    /** The last step a worker took: a message delivered twice does not play its step twice. */
    #[ORM\Column(name: 'claimed_step', type: 'integer')]
    private int $claimedStep = -1;

    /** True between the claim of a step and its end: a worker that stops between two steps loses nothing. */
    #[ORM\Column(name: 'step_in_flight', type: 'boolean')]
    private bool $stepInFlight = false;

    /** Tool calls made since the launch: the activity of the task, not a progress. */
    #[ORM\Column(type: 'integer')]
    private int $calls = 0;

    #[ORM\Column(name: 'last_tool', type: 'string', length: 128, nullable: true)]
    private ?string $lastTool = null;

    #[ORM\Column(name: 'last_call_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCallAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param list<string> $manifest the tools the scripts name: the only ones the worker will call
     * @param list<string> $steps    the names of the steps, in the order they are played
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private readonly string $id,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private readonly ?string $owner,
        #[ORM\Column(type: 'text')]
        private readonly string $objective,
        #[ORM\Column(type: 'text')]
        private readonly string $recap,
        #[ORM\Column(type: 'text')]
        private readonly string $reading,
        #[ORM\Column(type: 'json')]
        private readonly array $manifest,
        #[ORM\Column(type: 'json')]
        private readonly array $steps,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function getObjective(): string
    {
        return $this->objective;
    }

    public function getRecap(): string
    {
        return $this->recap;
    }

    public function getReading(): string
    {
        return $this->reading;
    }

    /**
     * @return list<string>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * @return list<string>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getStatus(): TaskStatus
    {
        return TaskStatus::from($this->status);
    }

    public function setStatus(TaskStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function setCurrentStep(int $step): void
    {
        $this->currentStep = $step;
    }

    public function getClaimedStep(): int
    {
        return $this->claimedStep;
    }

    public function setClaimedStep(int $step): void
    {
        $this->claimedStep = $step;
    }

    public function isStepInFlight(): bool
    {
        return $this->stepInFlight;
    }

    public function setStepInFlight(bool $stepInFlight): void
    {
        $this->stepInFlight = $stepInFlight;
    }

    public function getCalls(): int
    {
        return $this->calls;
    }

    public function getLastTool(): ?string
    {
        return $this->lastTool;
    }

    public function getLastCallAt(): ?\DateTimeImmutable
    {
        return $this->lastCallAt;
    }

    public function countCall(string $tool, \DateTimeImmutable $at): void
    {
        ++$this->calls;
        $this->lastTool = $tool;
        $this->lastCallAt = $at;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }
}
