<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Adapter;

use Emagma\McpSyliusBundle\AdminApi\Adapter\AbstractSyliusApiAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;

/** A line that leaves the default layout every way a line can. */
final readonly class RootCouponsAdapter extends AbstractSyliusApiAdapter
{
    public const array ITEMS = [
        'promotion_coupon' => 'promotion-coupons/{code}',
        'province' => 'provinces/{code}',
    ];

    public const array COLLECTIONS = [
        'promotion_coupon' => 'promotions/{promotion}/coupons',
        'promotion_coupon POST' => 'promotion-coupons',
        'province' => 'countries/{country}/provinces',
    ];

    public const array ACTIONS = [
        'promotion_coupon generate' => 'promotion-coupons/generate',
    ];

    public function body(Route $route, string $method, array $body): array
    {
        if ('promotion_coupon' === $route->resource->routeKey() && null === $route->key && 'POST' === $method && isset($route->parents['promotion'])) {
            $body['promotion'] ??= (string) $route->parents['promotion'];
        }

        return $body;
    }
}
