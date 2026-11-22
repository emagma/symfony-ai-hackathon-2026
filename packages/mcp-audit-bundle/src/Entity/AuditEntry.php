<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;

/**
 * One row per write call of an MCP tool, or per person revealed. Keeps full `before` and `after`
 * snapshots, not only the diff, so a state can be restored without a schema migration.
 *
 * A forgotten entry (its values erased on request) is locked for good: forget() is then a no-op,
 * setRevertedBy() throws, and EventListener\AuditEntryLockListener refuses any update of its row.
 * Not final: Doctrine builds its proxies by inheritance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_audit')]
#[ORM\Index(name: 'IDX_emagma_mcp_audit_actor', columns: ['actor'])]
#[ORM\Index(name: 'idx_target', columns: ['target_type', 'target_code', 'created_at'])]
#[ORM\Index(name: 'idx_session', columns: ['session_id', 'created_at'])]
#[ORM\Index(name: 'idx_batch', columns: ['batch_id'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When its values were erased; a trace of an erasure is born forgotten. */
    #[ORM\Column(name: 'forgotten_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $forgottenAt = null;

    #[ORM\OneToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reverted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AuditEntry $revertedBy = null;

    /** The entry that traces the erasure, by `forget_audit_personal_data` or its command. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'forgotten_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AuditEntry $forgottenBy = null;

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @param array<string, mixed> $diff
     * @param string|null          $batchId     One UUID shared by every item of one batch tool call
     * @param string|null          $actor       Null when the deployment has no identity to give (CLI, unauthenticated transport), rather than the service account
     * @param bool                 $revertible  False when the tool that recorded it is irreversible (#[Irreversible]): a revert is refused before anything is read
     */
    public function __construct(
        #[ORM\Column(name: 'tool', type: 'string', length: 64)]
        private readonly string $tool,
        #[ORM\Column(name: 'target_type', type: 'string', length: 32)]
        private readonly string $targetType,
        #[ORM\Column(name: 'target_code', type: 'string', length: 255)]
        private readonly string $targetCode,
        #[ORM\Column(name: 'before_state', type: 'json')]
        private array $beforeState,
        #[ORM\Column(name: 'after_state', type: 'json')]
        private array $afterState,
        #[ORM\Column(name: 'diff', type: 'json')]
        private array $diff,
        #[ORM\Column(name: 'session_id', type: 'string', length: 64, nullable: true)]
        private readonly ?string $sessionId = null,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'reverts_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        private readonly ?self $reverts = null,
        #[ORM\Column(name: 'actor', type: 'string', length: 255, nullable: true)]
        private readonly ?string $actor = null,
        #[ORM\Column(name: 'batch_id', type: 'string', length: 36, nullable: true)]
        private readonly ?string $batchId = null,
        #[ORM\Column(name: 'revertible', type: 'boolean', options: ['default' => true])]
        private readonly bool $revertible = true,
        bool $locked = false,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        if ($locked) {
            $this->forgottenAt = $this->createdAt;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetCode(): string
    {
        return $this->targetCode;
    }

    /** @return array<string, mixed> */
    public function getBeforeState(): array
    {
        return $this->beforeState;
    }

    /** @return array<string, mixed> */
    public function getAfterState(): array
    {
        return $this->afterState;
    }

    /** @return array<string, mixed> */
    public function getDiff(): array
    {
        return $this->diff;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getReverts(): ?self
    {
        return $this->reverts;
    }

    public function getRevertedBy(): ?self
    {
        return $this->revertedBy;
    }

    /**
     * @throws \LogicException on a forgotten entry, which is locked
     */
    public function setRevertedBy(?self $revertedBy): void
    {
        if ($this->isForgotten()) {
            throw new \LogicException(\sprintf('Audit entry #%d was forgotten: it is locked.', $this->id ?? 0));
        }
        $this->revertedBy = $revertedBy;
    }

    public function isRevertible(): bool
    {
        return $this->revertible;
    }

    public function isForgotten(): bool
    {
        return null !== $this->forgottenAt;
    }

    public function getForgottenAt(): ?\DateTimeImmutable
    {
        return $this->forgottenAt;
    }

    public function getForgottenBy(): ?self
    {
        return $this->forgottenBy;
    }

    /**
     * Every value of the snapshots and the diff becomes the redaction marker; the keys and the row stay,
     * and the entry is locked. A no-op on an entry already forgotten.
     * `$marker` is the redactor's ({@see SensitiveDataRedactor::marker()}), `$trace` the entry that traces the erasure.
     */
    public function forget(string $marker = SensitiveDataRedactor::MARKER, ?self $trace = null): void
    {
        if ($this->isForgotten()) {
            return;
        }

        /** @var array<string, mixed> $before */
        $before = self::scrub($this->beforeState, $marker);
        /** @var array<string, mixed> $after */
        $after = self::scrub($this->afterState, $marker);
        /** @var array<string, mixed> $diff */
        $diff = self::scrub($this->diff, $marker);

        $this->beforeState = $before;
        $this->afterState = $after;
        $this->diff = $diff;
        $this->forgottenAt = new \DateTimeImmutable();
        $this->forgottenBy = $trace;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function scrub(array $values, string $marker): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = \is_array($value) ? self::scrub($value, $marker) : $marker;
        }

        return $values;
    }
}
