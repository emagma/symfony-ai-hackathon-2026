<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PromotionCoupon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\PromotionCoupon\PromotionCouponOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads promotion coupons one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_promotion_coupons',
    description: 'Get coupons of one cart promotion by code, at most 50 per call: code, usageLimit, used,'
        . ' perCustomerUsageLimit, reusableFromCancelledOrders, expiresAt, createdAt. Returns {found:'
        . ' [...], missing: [codes]} (a coupon under another promotion is missing). Discover codes with'
        . ' search_promotion_coupons.',
)]
#[Permission(resource: 'promotion', action: Action::Read)]
final readonly class GetPromotionCoupons
{
    private const int MAX_CODES = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return array{found: list<PromotionCouponOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        string $promotionCode,
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $codes,
    ): array {
        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => '' !== trim($code))));
        if (\count($codes) > self::MAX_CODES) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_codes'];
        }
        if ([] === $codes) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($codes as $code) {
            $paths[$code] = Route::item(Resource::PromotionCoupon, $code, ['promotion' => $promotionCode]);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($codes as $code) {
            $result = $results[$code] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = PromotionCouponOutput::fromPayload($result['body'], $promotionCode, $code);
            } else {
                $missing[] = $code;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
