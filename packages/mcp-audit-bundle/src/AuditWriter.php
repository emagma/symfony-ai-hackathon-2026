<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;

/**
 * Redacts every snapshot here, the one place all write tools pass through, and an email given as target code.
 * Never throws: the backend has already changed, so a lost entry goes to the logger at CRITICAL.
 *
 * @internal
 */
final class AuditWriter implements WriteJournalInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SensitiveDataRedactor $redactor,
        private readonly ActorProviderInterface $actorProvider = new NullActorProvider(),
        private readonly ?LoggerInterface $logger = null,
        private readonly ToolClassMap $tools = new ToolClassMap(),
    ) {
    }

    public function record(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
        $this->recordEntry($tool, $targetType, $targetCode, $beforeState, $afterState, $diff, $context, batchId: $batchId);
    }

    public function stage(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
        $this->stageEntry($tool, $targetType, $targetCode, $beforeState, $afterState, $diff, $context, batchId: $batchId);
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @param array<string, mixed> $diff
     */
    public function recordEntry(
        string $tool,
        string $targetType,
        string $targetCode,
        array $beforeState,
        array $afterState,
        array $diff,
        ?RequestContext $context = null,
        ?AuditEntry $reverts = null,
        ?string $batchId = null,
        bool $locked = false,
    ): AuditEntry {
        $entry = $this->stageEntry(
            tool: $tool,
            targetType: $targetType,
            targetCode: $targetCode,
            beforeState: $beforeState,
            afterState: $afterState,
            diff: $diff,
            context: $context,
            reverts: $reverts,
            batchId: $batchId,
            locked: $locked,
        );
        $this->flush();

        return $entry;
    }

    /**
     * `$locked`: born forgotten, the trace of an erasure.
     *
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @param array<string, mixed> $diff
     */
    public function stageEntry(
        string $tool,
        string $targetType,
        string $targetCode,
        array $beforeState,
        array $afterState,
        array $diff,
        ?RequestContext $context = null,
        ?AuditEntry $reverts = null,
        ?string $batchId = null,
        bool $locked = false,
    ): AuditEntry {
        $entry = new AuditEntry(
            tool: $tool,
            targetType: $targetType,
            // A tool must give an id; an email that slips through is not stored as given.
            targetCode: $this->redactor->redactText($targetCode),
            beforeState: $this->redactor->redact($beforeState),
            afterState: $this->redactor->redact($afterState),
            diff: $this->redactor->redactDiff($diff),
            sessionId: $context?->getSession()->getId()->toString(),
            reverts: $reverts,
            actor: $this->actorProvider->actor(),
            batchId: $batchId,
            revertible: $this->revertible($tool),
            locked: $locked,
        );

        try {
            $this->em->persist($entry);
        } catch (\Throwable $exception) {
            // persist() throws too: a closed EntityManager, a mapping the host never registered.
            $this->reportLostEntry($entry, $exception);
        }

        return $entry;
    }

    /**
     * What the class of the tool says; a class that cannot be read says nothing, and the revert decides.
     */
    private function revertible(string $tool): bool
    {
        try {
            return !$this->tools->isIrreversible($tool);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Never throws: the backend has already changed by the time this runs.
     */
    public function flush(): void
    {
        try {
            $this->em->flush();
        } catch (\Throwable $exception) {
            $this->reportLostEntries($exception);
        }
    }

    /**
     * A failed flush closes the EntityManager: the entries are read out of the unit of work before anything else touches it.
     */
    private function reportLostEntries(\Throwable $exception): void
    {
        $pending = [];

        try {
            foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
                if ($scheduled instanceof AuditEntry) {
                    $pending[] = $scheduled;
                }
            }
        } catch (\Throwable) {
            // A broken EntityManager must not fail the tool call on top of the lost entry.
            $pending = [];
        }

        if (null === $this->logger) {
            return;
        }

        if ([] === $pending) {
            $this->logger->critical('MCP audit entry could not be persisted and could not be recovered.', [
                'exception' => $exception,
            ]);

            return;
        }

        foreach ($pending as $entry) {
            $this->reportLostEntry($entry, $exception);
        }
    }

    /**
     * The payload is already redacted: the log is no more exposed than the table.
     */
    private function reportLostEntry(AuditEntry $entry, \Throwable $exception): void
    {
        $this->logger?->critical('MCP audit entry could not be persisted: recorded here instead.', [
            'tool' => $entry->getTool(),
            'targetType' => $entry->getTargetType(),
            'targetCode' => $entry->getTargetCode(),
            'actor' => $entry->getActor(),
            'sessionId' => $entry->getSessionId(),
            'diff' => $entry->getDiff(),
            'exception' => $exception,
        ]);
    }

    public function markReverted(AuditEntry $original, AuditEntry $revertEntry): void
    {
        if ($original->getRevertedBy() === $revertEntry) {
            return;
        }
        $original->setRevertedBy($revertEntry);
        $this->flush();
    }
}
