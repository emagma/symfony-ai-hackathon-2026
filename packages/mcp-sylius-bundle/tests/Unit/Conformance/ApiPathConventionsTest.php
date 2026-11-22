<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\ApiPathConventionsTestCase;
use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ApiPathConventionsTest extends ApiPathConventionsTestCase
{
    protected static function tools(): ToolSources
    {
        return SyliusSources::tools();
    }
}
