<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\EventListener;

use Doctrine\ORM\Event\PreUpdateEventArgs;
use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * A forgotten entry is the proof of an erasure: once forgotten, its row is never updated again.
 * The update that forgets it is the one let through. The purge deletes it in DQL, without this listener.
 *
 * @internal
 */
final class AuditEntryLockListener
{
    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entry = $event->getObject();
        if (!$entry instanceof AuditEntry || !$entry->isForgotten()) {
            return;
        }
        if ($event->hasChangedField('forgottenAt') && null === $event->getOldValue('forgottenAt')) {
            return;
        }

        throw new \LogicException(\sprintf('Audit entry #%d was forgotten: it is locked.', $entry->getId() ?? 0));
    }
}
