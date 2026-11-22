<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

/**
 * The account the assistant authenticates with, which no tool may lock out.
 * Needs a `string $serviceAccountEmail` property on the host.
 *
 * @internal
 */
trait ServiceAccountTrait
{
    /**
     * @param array<string, mixed> $current
     */
    private function isServiceAccount(array $current): bool
    {
        $email = $current['email'] ?? null;

        return '' !== $this->serviceAccountEmail &&
            \is_string($email) &&
            mb_strtolower($email) === mb_strtolower($this->serviceAccountEmail);
    }
}
