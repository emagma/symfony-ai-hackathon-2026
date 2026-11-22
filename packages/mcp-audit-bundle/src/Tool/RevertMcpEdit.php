<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tool;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpAuditBundle\Revert\RevertPlan;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\WidenedBy;
use Emagma\McpCoreBundle\Exception\RevertRefusedException;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * Refused before anything is read: another's entry (`not_found`), an entry of a tool the caller may not
 * call (`forbidden`), a forgotten one (`redacted_snapshot`), one of an #[Irreversible] tool or a creation
 * (`nothing_to_restore`). A transition the tool did not declare cannot be detected in advance: the revert
 * re-reads the resource afterwards and answers `revert_incomplete` instead of claiming a success.
 *
 * @internal
 */
#[McpTool(
    name: 'revert_mcp_edit',
    description: 'Revert a previous MCP write: looks up the audit entry by id (from search_mcp_edits) and writes back'
        . ' the snapshot captured before that edit; with batchId instead, every edit'
        . ' of one batch write (an upsert_* call) under one confirmation, one result per entry. Works for any edit of an'
        . ' existing resource (every update_* tool). It cannot undo a creation, a deletion, or a state'
        . ' transition (an order cancelled, a payment refunded, ...): nothing_to_restore, or,'
        . ' when the backend keeps a state the write did not move, revert_incomplete with the fields that did not'
        . ' move. Reverts the caller\'s own edits (any edit under the audit_all grant), with the grant of the tool'
        . ' that made the edit (forbidden otherwise). Returns {reverted: true, id, newEntryId, targetType,'
        . ' targetCode, diff}. Tool-specific reasons: id_or_batch_id, already_reverted_by, nothing_to_restore,'
        . ' unrevertable_snapshot, redacted_snapshot, target_not_found, already_at_target_state,'
        . ' revert_incomplete, nothing_to_revert.',
)]
#[Permission(resource: 'audit', action: Action::Write)]
#[WidenedBy(resource: AuditScope::ALL, action: Action::Write)]
final readonly class RevertMcpEdit
{
    use WriteConfirmationTrait;

    /** Above this many characters, a nested value is counted in the preview rather than printed. */
    private const int PREVIEW_INLINE_MAX = 120;

    public function __construct(
        private RevertTargetInterface $target,
        private AuditRepositoryInterface $repository,
        private AuditWriter $audit,
        private AuditScope $scope,
        private SensitiveDataRedactor $redactor = new SensitiveDataRedactor(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $id = null,
        ?string $batchId = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ((null === $id) === (null === $batchId)) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'id_or_batch_id',
                'hint' => 'Give id (one edit) or batchId (every edit of one batch write), not both.',
            ];
        }
        if (null !== $batchId) {
            return $this->revertBatch($batchId, $confirm, $context);
        }
        \assert(null !== $id);

        $entry = $this->repository->find($id);
        // Another's entry answers as a missing one.
        if (null === $entry || !$this->scope->reaches($entry, Action::Write)) {
            return ['ok' => false, 'reverted' => false, 'reason' => 'not_found', 'id' => $id];
        }
        $plan = $this->plan($entry, $id);
        if (!$plan instanceof RevertPlan) {
            return $plan;
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm revert of edit #' . $id,
            'Apply this revert?',
            $this->renderRevertPreview($entry, $plan->diff),
            'Re-invoke with confirm: true to apply this revert.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'reverted' => false, 'id' => $id, 'diff' => $plan->diff] + $refusal;
        }

        return $this->apply($plan, $context);
    }

    /**
     * An edit that cannot be reverted is reported in its result and does not stop the others.
     *
     * @return array<string, mixed>
     */
    private function revertBatch(string $batchId, bool $confirm, ?RequestContext $context): array
    {
        $actor = $this->scope->actor(Action::Write);
        $entries = false === $actor ? [] : $this->repository->findByBatch($batchId, $actor);
        if ([] === $entries) {
            return ['ok' => false, 'reverted' => false, 'reason' => 'not_found', 'batchId' => $batchId];
        }

        $results = [];
        $plans = [];
        foreach ($entries as $entry) {
            $plan = $this->plan($entry);
            if ($plan instanceof RevertPlan) {
                $plans[] = $plan;
            } else {
                $results[(string) $entry->getId()] = $plan;
            }
        }
        if ([] === $plans) {
            return ['ok' => false, 'reverted' => false, 'reason' => 'nothing_to_revert', 'batchId' => $batchId, 'results' => $results];
        }

        $preview = implode("\n\n", array_map(fn (RevertPlan $plan): string => $this->renderRevertPreview($plan->entry, $plan->diff), $plans));
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            \sprintf('Confirm revert of %d edit(s) of batch %s', \count($plans), $batchId),
            'Apply these reverts?',
            $preview,
            'Re-invoke with confirm: true to apply these reverts.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'reverted' => false, 'batchId' => $batchId, 'total' => \count($plans)] + $refusal;
        }

        foreach ($plans as $plan) {
            $results[(string) $plan->entry->getId()] = $this->apply($plan, $context);
        }
        $done = \count(array_filter($results, static fn (array $result): bool => true === ($result['reverted'] ?? false)));

        return [
            'ok' => $done === \count($results),
            'batchId' => $batchId,
            'total' => \count($results),
            'reverted' => $done,
            'failed' => \count($results) - $done,
            'results' => $results,
        ];
    }

    /**
     * @return RevertPlan|array<string, mixed>
     */
    private function plan(AuditEntry $entry, ?int $id = null): RevertPlan|array
    {
        $id ??= $entry->getId();
        // A revert writes what the recording tool wrote: who may not call it may not undo it either.
        if (!$this->scope->mayCall($entry->getTool())) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'forbidden',
                'id' => $id,
                'tool' => $entry->getTool(),
                'hint' => 'Reverting this edit needs the grants of the tool that made it.',
            ];
        }
        if (null !== $entry->getRevertedBy()) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'already_reverted_by',
                'id' => $id,
                'revertedById' => $entry->getRevertedBy()->getId(),
            ];
        }

        if ($entry->isForgotten()) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'redacted_snapshot',
                'id' => $id,
                'hint' => 'The values of this entry were erased on request: there is nothing left to replay.',
            ];
        }

        if (!$entry->isRevertible()) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'nothing_to_restore',
                'id' => $id,
                'hint' => \sprintf('%s cannot be undone by writing the previous state back (a deletion, a state transition, a file replaced, an email sent).', $entry->getTool()),
            ];
        }

        $target = $entry->getBeforeState();
        if ([] === $target) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'nothing_to_restore',
                'id' => $id,
                'hint' => 'This entry recorded a creation: there is no previous state to put back.'
                    . ' Delete the created resource instead, if that is what you meant.',
            ];
        }

        // Writing a scrubbed snapshot back would store the redaction marker as if it were data.
        if ($this->redactor->containsRedaction($target)) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'redacted_snapshot',
                'id' => $id,
                'hint' => 'The stored snapshot was scrubbed of sensitive values and cannot be replayed.',
            ];
        }

        $path = $this->target->locate($target);
        if (null === $path) {
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'unrevertable_snapshot',
                'id' => $id,
                'targetType' => $entry->getTargetType(),
                'hint' => 'The snapshot carries no address the backend can write to, so there is nowhere to restore it.'
                    . ' Sub-resources written through their parent are reverted by editing the parent.',
            ];
        }

        try {
            $current = $this->target->read($path);
        } catch (RevertRefusedException $refused) {
            return ['ok' => false, 'reverted' => false, 'id' => $id, 'targetCode' => $entry->getTargetCode(), 'hint' => 'The resource no longer exists: a deletion cannot be reverted by writing to it.']
                + ($refused->notFound ? ['reason' => 'target_not_found'] : $refused->failure);
        }

        $diff = $this->target->diff($current, $target);
        if ([] === $diff) {
            return ['ok' => false, 'reverted' => false, 'reason' => 'already_at_target_state', 'id' => $id];
        }

        return new RevertPlan($id, $entry, $path, $current, $target, $diff);
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(RevertPlan $plan, ?RequestContext $context): array
    {
        $id = $plan->id;
        $entry = $plan->entry;
        $current = $plan->current;
        $target = $plan->target;
        $diff = $plan->diff;
        $path = $plan->path;

        try {
            $updated = $this->target->write($path, $target);
        } catch (RevertRefusedException $refused) {
            return ['ok' => false, 'reverted' => false, 'id' => $id] + $refused->failure;
        }

        // Recorded before the check: an incomplete revert still changed the backend.
        $newEntry = $this->audit->recordEntry(
            tool: 'revert_mcp_edit',
            targetType: $entry->getTargetType(),
            targetCode: $entry->getTargetCode(),
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
            reverts: $entry,
        );

        $remaining = $this->target->diff($updated, $target);
        if ([] !== $remaining) {
            // Not marked as reverted, so a later attempt is not blocked.
            return [
                'ok' => false,
                'reverted' => false,
                'reason' => 'revert_incomplete',
                'id' => $id,
                'newEntryId' => $newEntry->getId(),
                'applied' => array_values(array_diff(array_keys($diff), array_keys($remaining))),
                'notApplied' => $remaining,
                'hint' => 'The backend kept part of its current state, typically a state machine field, which'
                    . ' no plain write can move. Use the domain action instead where one exists.',
            ];
        }

        $this->audit->markReverted($entry, $newEntry);

        return [
            'ok' => true,
            'reverted' => true,
            'id' => $id,
            'newEntryId' => $newEntry->getId(),
            'targetType' => $entry->getTargetType(),
            'targetCode' => $entry->getTargetCode(),
            'diff' => $diff,
        ];
    }

    /**
     * @param array<string, array{before: mixed, after: mixed}> $diff
     */
    private function renderRevertPreview(AuditEntry $entry, array $diff): string
    {
        $lines = [
            sprintf(
                'Revert edit #%d (%s, tool=%s, target=%s/%s)',
                $entry->getId() ?? 0,
                $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
                $entry->getTool(),
                $entry->getTargetType(),
                $entry->getTargetCode(),
            ),
            '',
        ];

        foreach ($diff as $field => $pair) {
            $lines[] = self::isNested($pair['before']) || self::isNested($pair['after'])
                ? sprintf('- %s: %s', $field, $this->nestedSummary($pair['before'], $pair['after']))
                : sprintf(
                    '- %s: %s -> %s',
                    $field,
                    DiffPreview::format($pair['before']),
                    DiffPreview::format($pair['after']),
                );
        }

        return implode("\n", $lines);
    }

    private static function isNested(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (\is_array($item)) {
                return true;
            }
        }

        return false;
    }

    private function nestedSummary(mixed $before, mixed $after): string
    {
        $short = DiffPreview::format($this->target->readable($before)) . ' -> ' . DiffPreview::format($this->target->readable($after));
        if (mb_strlen($short) <= self::PREVIEW_INLINE_MAX) {
            return $short;
        }
        $count = static fn (mixed $value): string => \is_array($value) ? \count($value) . (1 === \count($value) ? ' item' : ' items') : DiffPreview::format($value);
        $from = $count($before);
        $to = $count($after);

        return $from === $to ? $from . ' changed' : $from . ' -> ' . $to;
    }
}
