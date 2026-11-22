<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PromotionCoupon;

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
 * Deletes promotion coupons in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_promotion_coupons',
    description: 'Delete coupons of one cart promotion by code, at most 50 per call, after one confirmation for'
        . ' the batch. A coupon already used by an order is refused by the shop and the others go through.'
        . ' Returns {ok, batchId, total, deleted, failed, results: {code: {ok, action, reason?}}}. Not'
        . ' revertable.',
)]
#[Permission(resource: 'promotion', action: Action::Delete)]
final readonly class DeletePromotionCoupons
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
        string $promotionCode,
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $codes,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runDelete('delete_promotion_coupons', Route::collection(Resource::PromotionCoupon, ['promotion' => $promotionCode]), $codes, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'promotion_coupon';
    }

    private function label(): string
    {
        return 'promotion coupons';
    }

    private function nameOf(array $current): ?string
    {
        $used = AdminPayload::int($current['used'] ?? null);

        return null === $used ? null : 'used ' . $used . ' time(s)';
    }
}
