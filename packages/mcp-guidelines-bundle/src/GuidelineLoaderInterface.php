<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle;

/**
 * Reads one resource of `emagma_mcp_guidelines.resources`. A loader is a service tagged `emagma_mcp_guidelines.loader` with an
 * `alias`; a resource names it with `loader`, or gets the Markdown loader when it is a `.md` file.
 */
interface GuidelineLoaderInterface
{
    /**
     * The text of the guideline, in Markdown; null when the resource cannot be read now (the guideline is left
     * out and logged: a text is never refused because a guideline could not be read).
     */
    public function load(string $resource): ?string;
}
