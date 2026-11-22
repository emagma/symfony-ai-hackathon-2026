<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Integration\Fixture;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\WidenedBy;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(name: 'echo', description: 'Returns the text it was given.')]
#[Permission(resource: 'echo', action: Action::Read)]
#[WidenedBy(resource: 'echo_all', action: Action::Read)]
final class EchoTool
{
    public function __invoke(string $text): string
    {
        return $text;
    }
}
