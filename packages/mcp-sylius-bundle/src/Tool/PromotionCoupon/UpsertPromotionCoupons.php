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
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Creates or updates promotion coupons in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_promotion_coupons',
    description: 'Create or update the coupons of one cart promotion in one call, at most 50 items, matched'
        . ' on their code. Fields: usageLimit, perCustomerUsageLimit, reusableFromCancelledOrders,'
        . ' expiresAt (null clears a limit or the expiry). The promotion must be couponBased. mode: upsert'
        . ' (default), create (already_exists on an existing code) or update (not_found on an unknown'
        . ' one). An item that changes nothing is unchanged and not written. One confirmation for the'
        . ' batch; one result per item. For a batch of random codes use generate_promotion_coupons.'
        . ' Tool-specific reasons: promotion_not_found, promotion_not_coupon_based.',
)]
#[Permission(resource: 'promotion', action: Action::Write)]
final readonly class UpsertPromotionCoupons
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const array FIELDS = ['usageLimit', 'perCustomerUsageLimit', 'reusableFromCancelledOrders', 'expiresAt'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $promotionCode,
        #[Schema(type: 'array', items: PromotionCouponItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        try {
            $promotion = $this->client->getDecoded(Route::item(Resource::Promotion, $promotionCode));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['total' => \count($items), 'promotionCode' => $promotionCode], notFound: 'promotion_not_found');
        }
        if (true !== ($promotion['couponBased'] ?? false)) {
            return [
                'ok' => false,
                'total' => \count($items),
                'reason' => 'promotion_not_coupon_based',
                'promotionCode' => $promotionCode,
                'hint' => 'Set couponBased: true with upsert_promotions first, or the coupons would never apply.',
            ];
        }

        return $this->runUpsert('upsert_promotion_coupons', Route::collection(Resource::PromotionCoupon, ['promotion' => $promotionCode]), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'promotion_coupon';
    }

    private function label(): string
    {
        return 'promotion coupons';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['usageLimit', 'perCustomerUsageLimit'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field] && (!\is_int($item[$field]) || $item[$field] < 0)) {
                return $field . ' is a non-negative integer or null';
            }
        }
        if (\array_key_exists('reusableFromCancelledOrders', $item) && !\is_bool($item['reusableFromCancelledOrders'])) {
            return 'reusableFromCancelledOrders is a boolean';
        }
        if (\array_key_exists('expiresAt', $item) && null !== $item['expiresAt'] && (!\is_string($item['expiresAt']) || false === strtotime($item['expiresAt']))) {
            return 'expiresAt is an ISO-8601 date-time or null';
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            $diff['reusableFromCancelledOrders'] = ['before' => null, 'after' => $item['reusableFromCancelledOrders'] ?? true];
            foreach (['usageLimit', 'perCustomerUsageLimit', 'expiresAt'] as $field) {
                if (\array_key_exists($field, $item) && null !== $item[$field]) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        $diff = [];
        foreach (self::FIELDS as $field) {
            if (\array_key_exists($field, $item) && ($current[$field] ?? null) !== $item[$field]) {
                $diff[$field] = ['before' => $current[$field] ?? null, 'after' => $item[$field]];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = ['code' => $item['code'], 'reusableFromCancelledOrders' => $item['reusableFromCancelledOrders'] ?? true];
        foreach (['usageLimit', 'perCustomerUsageLimit', 'expiresAt'] as $field) {
            if (\array_key_exists($field, $item) && null !== $item[$field]) {
                $body[$field] = $item[$field];
            }
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        foreach (self::FIELDS as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }

        return $body;
    }
}
