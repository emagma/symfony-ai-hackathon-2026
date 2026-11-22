<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\File;

/**
 * `$failure` is the tool's answer without the tool's own keys (`ok`, `created`, `code`).
 */
final class FileInputFailure extends \RuntimeException
{
    /**
     * @param array<string, mixed> $failure
     */
    public function __construct(
        public readonly array $failure,
    ) {
        parent::__construct(\is_string($failure['reason'] ?? null) ? $failure['reason'] : 'file_input_failure');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function because(string $reason, string $hint, array $details = []): self
    {
        return new self(['reason' => $reason] + $details + ['hint' => $hint]);
    }
}
