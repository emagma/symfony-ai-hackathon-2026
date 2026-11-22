<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tool;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Forget\PersonalDataEraser;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * Irreversible, as every delete tool: the values are gone, the entries locked.
 * The preview names the entries (id, tool, date), never a value.
 *
 * @internal
 */
#[McpTool(
    name: 'forget_audit_personal_data',
    description: 'Erase what the audit recorded about a person, on an erasure request: every value of the entries'
        . ' written on the subject, or referencing it, becomes a redaction marker; the rows stay, locked, and can'
        . ' no longer be reverted. type is the target type as the audit records it ("customer"), id its id. One'
        . ' new entry records the erasure, counts only. The shop\'s own data is untouched. Returns {ok, type, id,'
        . ' forgotten, ids}; entries already forgotten are skipped and not counted. Tool-specific reason:'
        . ' not_found (no entry concerns the subject).',
)]
#[Permission(resource: AuditScope::ALL, action: Action::Delete)]
final readonly class ForgetAuditPersonalData
{
    use WriteConfirmationTrait;

    public function __construct(
        private PersonalDataEraser $eraser,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $type,
        string $id,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $type = trim($type);
        $id = trim($id);
        if ('' === $type || '' === $id) {
            return ['ok' => false, 'reason' => 'validation_error', 'errors' => [['property' => '' === $type ? 'type' : 'id', 'message' => 'a subject needs a type and an id']]];
        }

        $entries = $this->eraser->concerning($type, $id);
        if ([] === $entries) {
            return ['ok' => false, 'reason' => 'not_found', 'type' => $type, 'id' => $id];
        }
        $pending = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => !$entry->isForgotten()));
        if ([] === $pending) {
            return ['ok' => true, 'type' => $type, 'id' => $id, 'forgotten' => 0, 'ids' => []];
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            \sprintf('Confirm the erasure of %s %s from the audit', $type, $id),
            'Erase these entries for good?',
            self::preview($type, $id, $pending),
            'Re-invoke with confirm: true to apply this erasure.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'type' => $type, 'id' => $id, 'forgotten' => 0] + $refusal;
        }

        $done = $this->eraser->forget($type, $id, $pending, $context);

        return [
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'forgotten' => \count($done['forgotten']),
            'ids' => array_map(static fn (AuditEntry $entry): ?int => $entry->getId(), $done['forgotten']),
        ];
    }

    /**
     * @param list<AuditEntry> $entries
     */
    private static function preview(string $type, string $id, array $entries): string
    {
        $lines = [
            \sprintf('Erase every value of %d audit %s concerning %s %s. They can no longer be reverted.', \count($entries), 1 === \count($entries) ? 'entry' : 'entries', $type, $id),
            '',
        ];
        foreach ($entries as $entry) {
            $lines[] = \sprintf('- #%d %s %s', $entry->getId() ?? 0, $entry->getTool(), $entry->getCreatedAt()->format(\DateTimeInterface::ATOM));
        }

        return implode("\n", $lines);
    }
}
