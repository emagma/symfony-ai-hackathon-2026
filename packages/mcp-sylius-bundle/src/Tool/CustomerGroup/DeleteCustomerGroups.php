<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CustomerGroup;

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
 * Deletes customer groups in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_customer_groups',
    description: 'Delete customer groups by code, at most 50 per call, after one confirmation for the batch.'
        . ' A group still assigned to customers is refused by the shop (validation_error or conflict) and'
        . ' the others go through. Returns {ok, batchId, total, deleted, failed, results: {code:'
        . ' {ok, action, reason?}}}. Not revertable.',
)]
#[Permission(resource: 'customer_group', action: Action::Delete)]
final readonly class DeleteCustomerGroups
{
    use BatchDeleteTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $codes,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runDelete('delete_customer_groups', Route::collection(Resource::CustomerGroup), $codes, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'customer_group';
    }

    private function label(): string
    {
        return 'customer groups';
    }

    private function nameOf(array $current): ?string
    {
        return AdminPayload::string($current['name'] ?? null);
    }
}
