<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\File;

/**
 * The MIME type is sniffed from the bytes, never the model's or the client's word.
 */
final readonly class ResolvedFile
{
    public int $size;

    public function __construct(
        public string $bytes,
        public string $filename,
        public string $mimeType,
        /** `file_id` of a file the client holds, for the audit; null for inline content. */
        public ?string $fileId = null,
    ) {
        $this->size = \strlen($bytes);
    }
}
