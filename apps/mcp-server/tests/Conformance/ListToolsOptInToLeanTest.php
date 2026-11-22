<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Testing\Conformance\ListToolsOptInToLeanTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ListToolsOptInToLeanTest extends ListToolsOptInToLeanTestCase
{
    protected static function tools(): ToolSources
    {
        return ServerSources::tools();
    }

    protected static function minimumChecked(): int
    {
        return 25;
    }

    protected static function fullPayloadByDesign(): array
    {
        return [
            'Audit\\SearchMcpEdits' => 'reads the audit database, not the shop',
            'Province\\SearchProvinces' => 'the provinces live in the full country payload',
            'Order\\ListOrderCollection' => 'the sub-collections live in the full order payload',
        ];
    }
}
