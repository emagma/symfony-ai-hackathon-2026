<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Revert;

use Emagma\McpCoreBundle\Exception\RevertRefusedException;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;

/**
 * The default until the backend's component provides a target: every revert answers `unrevertable_snapshot`.
 *
 * @internal
 */
final class NullRevertTarget implements RevertTargetInterface
{
    public function locate(array $snapshot): ?string
    {
        return null;
    }

    public function read(string $address): array
    {
        throw new RevertRefusedException(true, ['reason' => 'not_found']);
    }

    public function write(string $address, array $snapshot): array
    {
        throw new RevertRefusedException(true, ['reason' => 'not_found']);
    }

    public function diff(array $current, array $snapshot): array
    {
        return [];
    }

    public function readable(mixed $value): mixed
    {
        return $value;
    }
}
