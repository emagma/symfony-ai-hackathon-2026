<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

/**
 * An interface so that a host can decorate it to carry a per-user identity instead of the service account.
 */
interface JwtTokenProviderInterface
{
    public function getToken(): string;

    public function invalidate(): void;
}
