<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Mcp;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

/**
 * Narrows the tools handed to the model. An admin sees ~150 tools, whose
 * schemas ride along with every LLM call (~31k input tokens): a benchmark of
 * one domain can keep only the tools whose name starts with given prefixes.
 * Empty = everything the server grants the user.
 *
 * It also keeps away from the model the tools that only a screen may call.
 */
final class FilteredToolset implements ToolsetInterface
{
    /**
     * @param list<string> $prefixes
     */
    public function __construct(
        private readonly ToolsetInterface $inner,
        private readonly array $prefixes = [],
        private readonly ?AppTools $apps = null,
    ) {
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getTools(): array
    {
        // A tool only a screen may call (MCP Apps, `visibility: [app]`) is never the model's.
        $tools = array_values(array_filter(
            $this->inner->getTools(),
            fn (Tool $tool): bool => true !== $this->apps?->isAppOnly($tool),
        ));
        if ([] === $this->prefixes) {
            return $tools;
        }

        return array_values(array_filter(
            $tools,
            fn (Tool $tool): bool => array_any($this->prefixes, static fn (string $prefix): bool => str_starts_with($tool->name, $prefix)),
        ));
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        return $this->inner->callTool($name, $arguments);
    }
}
