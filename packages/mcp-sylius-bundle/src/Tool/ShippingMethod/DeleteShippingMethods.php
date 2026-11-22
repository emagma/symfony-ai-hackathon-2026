<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingMethod;

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
 * Deletes shipping methods in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_shipping_methods',
    description: 'Delete shipping methods by code, at most 50 per call, after one confirmation for the batch.'
        . ' A shipping method the shop still needs is refused (validation_error or conflict) and the'
        . ' others go through. Returns {ok, batchId, total, deleted, failed, results: {code:'
        . ' {ok, action, reason?}}}. Not revertable.',
)]
#[Permission(resource: 'shipping_method', action: Action::Delete)]
final readonly class DeleteShippingMethods
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
        return $this->runDelete('delete_shipping_methods', Route::collection(Resource::ShippingMethod), $codes, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'shipping_method';
    }

    private function label(): string
    {
        return 'shipping methods';
    }

    private function nameOf(array $current): ?string
    {
        return AdminPayload::string($current['name'] ?? null);
    }
}
