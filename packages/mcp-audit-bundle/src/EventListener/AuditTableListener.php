<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\EventListener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Emagma\McpAuditBundle\Entity\AuditEntry;

/**
 * The mapping names the default table: the name of `emagma_mcp_audit.table` is applied here, on the metadata.
 *
 * @internal
 */
final readonly class AuditTableListener
{
    public function __construct(
        private string $table,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (AuditEntry::class !== $metadata->getName()) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->table]);
    }
}
