<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

/**
 * An Admin API capability not every supported Sylius line has, with the version where it appeared.
 * Only what a tool depends on: a field merely read when present needs no case.
 */
enum ApiFeature: string
{
    /** `PATCH payments/{id}/refund`. */
    case PaymentRefund = 'payment_refund';

    case ZonePriority = 'zone_priority';

    public function since(): SyliusVersion
    {
        return new SyliusVersion(match ($this) {
            self::PaymentRefund => '2.0',
            self::ZonePriority => '2.2',
        });
    }

    public function isAvailableOn(SyliusVersion $version): bool
    {
        return $version->isAtLeast($this->since());
    }
}
