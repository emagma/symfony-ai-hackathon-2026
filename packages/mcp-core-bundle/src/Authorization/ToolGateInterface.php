<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

use Mcp\Capability\Registry\ToolReference;

/**
 * Extension point (tag `emagma_mcp_core.tool_gate`): why a tool is unusable on this deployment, whatever the grants.
 * A gate must answer the same for every caller: what depends on the caller belongs to the grants.
 */
interface ToolGateInterface
{
    public function refusal(ToolReference $reference): ?ToolRefusal;
}
