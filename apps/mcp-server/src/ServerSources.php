<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer;

use Emagma\McpAuditBundle\Tool\SearchMcpEdits;
use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\ToolSources;

/**
 * Every tool and output DTO the server exposes, for tests/Conformance and
 * bin/tools-weight.php.
 */
final class ServerSources
{
    /** Floors: an empty scan proves nothing. */
    public const int MIN_TOOL_CLASSES = 179;

    public const int MIN_OUTPUT_CLASSES = 47;

    private function __construct()
    {
    }

    public static function tools(): ToolSources
    {
        return SyliusSources::tools()->with(self::auditTools());
    }

    public static function outputs(): OutputCatalog
    {
        return SyliusSources::outputs()->with(self::auditOutputs());
    }

    /**
     * `Audit/` is the key the tests' exception lists are written with.
     */
    private static function auditTools(): ToolSources
    {
        return ToolSources::of(self::auditDir() . '/Tool', 'Emagma\McpAuditBundle\Tool\\', 'Audit/');
    }

    private static function auditOutputs(): OutputCatalog
    {
        return OutputCatalog::of(self::auditDir() . '/Output', 'Emagma\McpAuditBundle\Output\\');
    }

    private static function auditDir(): string
    {
        return \dirname((string) (new \ReflectionClass(SearchMcpEdits::class))->getFileName(), 2);
    }
}
