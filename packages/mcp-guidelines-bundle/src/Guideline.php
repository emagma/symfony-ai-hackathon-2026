<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle;

/**
 * One guideline: what the business is, or how it writes one kind of content, given whole to the model.
 */
final readonly class Guideline
{
    public function __construct(
        /** Its file name without `.md`, or the `name` of its resource. */
        public string $name,
        /** Markdown. */
        public string $content,
    ) {
    }
}
