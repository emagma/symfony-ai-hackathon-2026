<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Emagma\McpAgentsBundle\Level;

/**
 * One analysis of one thing by a background agent, whatever it concluded: the journal says what was
 * looked at, so that nothing is analysed twice, and what was found, for whoever asks later.
 * Not final: Doctrine builds its proxies by inheritance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_agent_report')]
#[ORM\UniqueConstraint(name: 'uniq_mcp_agent_report_source', columns: ['agent', 'source'])]
#[ORM\Index(name: 'idx_mcp_agent_report_agent', columns: ['agent', 'created_at'])]
class AgentReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(name: 'notified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    /**
     * @param list<string> $signals what was observed, one short sentence each, without personal data
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private readonly string $agent,
        /** What was analysed, unique per agent: the id of the event it came from. */
        #[ORM\Column(type: 'string', length: 64)]
        private readonly string $source,
        /** Where the agent stopped in what it reads (the sequence of the event). */
        #[ORM\Column(name: 'source_sequence', type: 'bigint')]
        private readonly int $sourceSequence,
        #[ORM\Column(name: 'subject_type', type: 'string', length: 32)]
        private readonly string $subjectType,
        #[ORM\Column(name: 'subject_id', type: 'string', length: 64)]
        private readonly string $subjectId,
        #[ORM\Column(type: 'string', length: 16)]
        private readonly string $level,
        #[ORM\Column(type: 'json')]
        private readonly array $signals,
        #[ORM\Column(type: 'text')]
        private readonly string $explanation,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $signals
     */
    public static function of(string $agent, string $source, int $sourceSequence, string $subjectType, string $subjectId, Level $level, array $signals, string $explanation, \DateTimeImmutable $createdAt): self
    {
        return new self($agent, $source, $sourceSequence, $subjectType, $subjectId, $level->value, $signals, $explanation, $createdAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): string
    {
        return $this->agent;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceSequence(): int
    {
        return $this->sourceSequence;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getLevel(): Level
    {
        return Level::from($this->level);
    }

    /**
     * @return list<string>
     */
    public function getSignals(): array
    {
        return $this->signals;
    }

    public function getExplanation(): string
    {
        return $this->explanation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function markNotified(\DateTimeImmutable $at): void
    {
        $this->notifiedAt = $at;
    }
}
