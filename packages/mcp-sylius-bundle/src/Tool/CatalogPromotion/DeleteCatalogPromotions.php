<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CatalogPromotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\AdminApi\ViolationExtractor;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchDeleteTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Deletes catalog promotions in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_catalog_promotions',
    description: 'Delete catalog promotions by code, at most 50 per call, after one confirmation for the batch.'
        . ' A catalog promotion the shop still needs is refused (validation_error or conflict) and the'
        . ' others go through. Returns {ok, batchId, total, deleted, failed, results: {code:'
        . ' {ok, action, reason?}}}. Not revertable. A promotion whose prices are still being recomputed is refused (processing). Tool-specific reasons: processing.',
)]
#[Permission(resource: 'catalog_promotion', action: Action::Delete)]
final readonly class DeleteCatalogPromotions
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
        return $this->runDelete('delete_catalog_promotions', Route::collection(Resource::CatalogPromotion), $codes, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'catalog_promotion';
    }

    private function label(): string
    {
        return 'catalog promotions';
    }

    private function nameOf(array $current): ?string
    {
        return AdminPayload::string($current['name'] ?? null);
    }

    /**
     * Sylius refuses to remove a promotion while its price recalculation is queued.
     *
     * @param array<string, mixed> $current
     */
    private function guard(string $key, array $current): ?string
    {
        return 'processing' === ($current['state'] ?? null) ? 'processing' : null;
    }

    /**
     * The state can move between the probe and the DELETE: Sylius then answers 400, not 409.
     *
     * @param array{ok: bool, status?: int|null, error?: string, body?: array<string, mixed>|null} $outcome
     *
     * @return array<string, mixed>
     */
    private function deleteFailure(array $outcome): array
    {
        if (400 === ($outcome['status'] ?? null)) {
            return ['ok' => false, 'action' => 'failed', 'reason' => 'processing', 'errors' => ViolationExtractor::fromPayload($outcome['body'] ?? null), 'hint' => UpsertCatalogPromotions::PROCESSING_HINT];
        }

        return ApiFailure::fromResult($outcome, ['action' => 'failed']);
    }
}
