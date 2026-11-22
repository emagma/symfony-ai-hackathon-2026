<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\ListToolsOptInToLeanTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ListToolsOptInToLeanTest extends ListToolsOptInToLeanTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }

    protected static function minimumChecked(): int
    {
        return 25;
    }

    protected static function fullPayloadByDesign(): array
    {
        return [
            'Province\\SearchProvinces' => 'the provinces live in the full country payload',
            'Order\\ListOrderCollection' => 'the sub-collections live in the full order payload',
        ];
    }
}
