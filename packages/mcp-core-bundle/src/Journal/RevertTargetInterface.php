<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Journal;

use Emagma\McpCoreBundle\Exception\RevertRefusedException;

/**
 * Extension point: the backend the audit's snapshots are restored to.
 */
interface RevertTargetInterface
{
    /**
     * The address a snapshot is restored to, or null when this target cannot write it.
     *
     * @param array<string, mixed> $snapshot
     */
    public function locate(array $snapshot): ?string;

    /**
     * @return array<string, mixed>
     *
     * @throws RevertRefusedException
     */
    public function read(string $address): array;

    /**
     * Returns the resource as the backend holds it after the write.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     *
     * @throws RevertRefusedException
     */
    public function write(string $address, array $snapshot): array;

    /**
     * Empty when writing the snapshot would change nothing. The backend's to say: only it knows what it regenerates on every read.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function diff(array $current, array $snapshot): array;

    /**
     * A payload value without the backend's bookkeeping, as shown in the preview of a revert.
     */
    public function readable(mixed $value): mixed;
}
