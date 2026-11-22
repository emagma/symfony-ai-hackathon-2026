<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle;

use Emagma\McpSyliusBundle\Output\OutputCatalog;

/**
 * The tools and the output DTOs of this bundle, scanned once for the server and for the checks of the whole.
 */
final class SyliusSources
{
    private function __construct()
    {
    }

    public static function tools(): ToolSources
    {
        return ToolSources::of(__DIR__ . '/Tool', 'Emagma\McpSyliusBundle\Tool\\');
    }

    public static function outputs(): OutputCatalog
    {
        return OutputCatalog::of(__DIR__ . '/Output', 'Emagma\McpSyliusBundle\Output\\');
    }
}
