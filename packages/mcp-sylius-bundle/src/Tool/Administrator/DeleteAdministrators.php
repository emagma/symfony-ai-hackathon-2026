<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

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
 * Deletes administrators in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_administrators',
    description: 'Delete Sylius BACK-OFFICE accounts by numeric id, at most 50 per call, after one confirmation'
        . ' for the batch. Prefer upsert_administrators with enabled: false, which is reversible. The account'
        . ' the assistant authenticates with is refused (service_account_protected) and the others go'
        . ' through. Returns {ok, batchId, total, deleted, failed, results: {id: {ok, action, reason?}}}.'
        . ' Not revertable.',
)]
#[Permission(resource: 'administrator', action: Action::Delete)]
final readonly class DeleteAdministrators
{
    use BatchDeleteTrait;
    use ServiceAccountTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $serviceAccountEmail,
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
        return $this->runDelete('delete_administrators', Route::collection(Resource::Administrator), $ids, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'administrator';
    }

    private function label(): string
    {
        return 'back-office accounts';
    }

    private function nameOf(array $current): ?string
    {
        return AdminPayload::string($current['username'] ?? null);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function guard(string $key, array $current): ?string
    {
        return $this->isServiceAccount($current) ? 'service_account_protected' : null;
    }
}
