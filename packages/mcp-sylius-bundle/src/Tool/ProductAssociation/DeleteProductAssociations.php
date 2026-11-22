<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchDeleteTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Deletes product associations in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_product_associations',
    description: 'Delete product associations by numeric id, at most 50 per call, after one confirmation for'
        . ' the batch. Returns {ok, batchId, total, deleted, failed, results: {id: {ok, action, reason?}}}.'
        . ' Not revertable.',
)]
#[Permission(resource: 'product_association', action: Action::Delete)]
final readonly class DeleteProductAssociations
{
    use BatchDeleteTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $ids,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runDelete('delete_product_associations', Route::collection(Resource::ProductAssociation), $ids, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_association';
    }

    private function label(): string
    {
        return 'product associations';
    }

    private function nameOf(array $current): ?string
    {
        $owner = AdminPayload::code($current['owner'] ?? null);
        $type = AdminPayload::code($current['type'] ?? null);

        return null === $owner || null === $type ? null : $owner . ' / ' . $type;
    }
}
