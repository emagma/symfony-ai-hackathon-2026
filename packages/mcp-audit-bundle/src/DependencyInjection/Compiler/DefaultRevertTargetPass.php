<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\DependencyInjection\Compiler;

use Emagma\McpAuditBundle\Forget\NoSubjectReferences;
use Emagma\McpAuditBundle\Revert\NullRevertTarget;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Aliases the two backend interfaces to their defaults only when no bundle did:
 * a compiler pass, so that the order the bundles are registered in does not matter.
 *
 * @internal
 */
final class DefaultRevertTargetPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(RevertTargetInterface::class)) {
            $container->register('emagma_mcp_audit.revert_target.null', NullRevertTarget::class);
            $container->setAlias(RevertTargetInterface::class, 'emagma_mcp_audit.revert_target.null');
        }
        if (!$container->has(SubjectReferencesInterface::class)) {
            $container->register('emagma_mcp_audit.subject_references.none', NoSubjectReferences::class);
            $container->setAlias(SubjectReferencesInterface::class, 'emagma_mcp_audit.subject_references.none');
        }
    }
}
