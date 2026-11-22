<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Forget;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Mcp\Server\RequestContext;

/**
 * An erasure request, for `forget_audit_personal_data` and `mcp:audit:forget-personal-data` alike:
 * the entries are scrubbed in place and locked, the rows stay, and one new entry traces the erasure
 * with counts only. Covers the server's copy only, not the backend's.
 *
 * @internal
 */
final readonly class PersonalDataEraser
{
    /** The tool name the trace is recorded under, whoever erased (the tool or the command). */
    public const string TOOL = 'forget_audit_personal_data';

    public function __construct(
        private AuditRepositoryInterface $repository,
        private AuditWriter $audit,
        private EntityManagerInterface $em,
        private SensitiveDataRedactor $redactor = new SensitiveDataRedactor(),
        private SubjectReferencesInterface $references = new NoSubjectReferences(),
    ) {
    }

    /**
     * The entries written on the subject, and those whose snapshots reference it: those already forgotten included.
     *
     * @return list<AuditEntry>
     */
    public function concerning(string $type, string $id): array
    {
        return $this->repository->findConcerning($type, $id, $this->references->suffixes($type, $id));
    }

    /**
     * An entry already forgotten is skipped and not counted; nothing left to forget writes no trace.
     * Unlike the journal, it throws: nothing has changed anywhere if the flush fails.
     *
     * @param list<AuditEntry> $entries what {@see concerning()} returned
     *
     * @return array{forgotten: list<AuditEntry>, trace: AuditEntry|null}
     */
    public function forget(string $type, string $id, array $entries, ?RequestContext $context = null): array
    {
        $pending = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => !$entry->isForgotten()));
        if ([] === $pending) {
            return ['forgotten' => [], 'trace' => null];
        }

        $count = \count($pending);
        $trace = $this->audit->stageEntry(
            tool: self::TOOL,
            targetType: $type,
            targetCode: $id,
            beforeState: [],
            afterState: ['forgotten' => $count],
            diff: ['forgotten' => ['before' => 0, 'after' => $count]],
            context: $context,
            locked: true,
        );
        foreach ($pending as $entry) {
            $entry->forget($this->redactor->marker(), $trace);
        }
        $this->em->flush();

        return ['forgotten' => $pending, 'trace' => $trace];
    }
}
