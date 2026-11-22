<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing;

/**
 * One write a tool recorded through SinkWriteJournal, as a test reads it back.
 */
final readonly class RecordedWrite
{
    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @param array<string, mixed> $diff
     */
    public function __construct(
        private string $tool,
        private string $targetType,
        private string $targetCode,
        private array $beforeState,
        private array $afterState,
        private array $diff,
        private ?string $sessionId = null,
        private ?string $batchId = null,
    ) {
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetCode(): string
    {
        return $this->targetCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBeforeState(): array
    {
        return $this->beforeState;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAfterState(): array
    {
        return $this->afterState;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiff(): array
    {
        return $this->diff;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }
}
