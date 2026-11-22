<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization\Fixture;

use Emagma\McpCoreBundle\Authorization\HandlerClass;
use Emagma\McpCoreBundle\Authorization\ToolGateInterface;
use Emagma\McpCoreBundle\Authorization\ToolRefusal;
use Mcp\Capability\Registry\ToolReference;

final readonly class NeedsGate implements ToolGateInterface
{
    /**
     * @param list<string> $available
     */
    public function __construct(
        private array $available = [],
    ) {
    }

    public function refusal(ToolReference $reference): ?ToolRefusal
    {
        $class = HandlerClass::of($reference->handler);
        $attributes = null === $class ? [] : (new \ReflectionClass($class))->getAttributes(Needs::class);
        if ([] === $attributes) {
            return null;
        }

        $what = $attributes[0]->newInstance()->what;
        if (\in_array($what, $this->available, true)) {
            return null;
        }

        return new ToolRefusal(
            ['ok' => false, 'reason' => 'backend_lacks', 'what' => $what, 'hint' => 'Tell the human; do not retry.'],
            'MCP tool call on a tool the backend cannot serve.',
            ['what' => $what],
        );
    }
}
