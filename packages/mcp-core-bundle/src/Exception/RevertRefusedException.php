<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Exception;

/**
 * The backend refused a revert: what the tool answers the model, and whether nothing exists at the address any more.
 */
final class RevertRefusedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $failure what the tool answers to the model
     */
    public function __construct(
        public readonly bool $notFound,
        public readonly array $failure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The revert target refused the request.', 0, $previous);
    }
}
