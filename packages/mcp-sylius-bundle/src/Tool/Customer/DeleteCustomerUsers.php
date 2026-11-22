<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Customer;

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
 * Deletes the shop login only: the admin API has no customer deletion at all.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_customer_users',
    description: 'Delete the shop ACCOUNT (login) of customers by numeric id, at most 50 per call, after one'
        . ' confirmation for the batch. The customer record, email and order history are kept: the'
        . ' customer becomes a guest. A customer without an account is refused (no_account);'
        . ' upsert_customers with accountEnabled: false locks an account reversibly instead. Returns'
        . ' {ok, batchId, total, deleted, failed, results: {id: {ok, action, reason?}}}. Not revertable.',
)]
#[Permission(resource: 'customer', action: Action::Delete)]
final readonly class DeleteCustomerUsers
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
        return $this->runDelete('delete_customer_users', Route::collection(Resource::Customer), $ids, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'customer';
    }

    private function label(): string
    {
        return 'customer shop accounts';
    }

    private function nameOf(array $current): ?string
    {
        return AdminPayload::string($current['email'] ?? null);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function guard(string $key, array $current): ?string
    {
        return null === ($current['user'] ?? null) ? 'no_account' : null;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function deletePath(Route $collection, string $key, array $current): Route
    {
        return Route::action($collection->resource, $key, 'user');
    }
}
