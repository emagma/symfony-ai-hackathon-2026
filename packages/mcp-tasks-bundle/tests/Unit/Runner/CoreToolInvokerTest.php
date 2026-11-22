<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Runner;

use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpTasksBundle\Runner\CoreToolInvoker;
use Emagma\McpTasksBundle\Runner\ToolCallFailed;
use Emagma\McpTasksBundle\Testing\FakeDelegation;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreToolInvoker::class)]
final class CoreToolInvokerTest extends TestCase
{
    private FakeDelegation $delegation;

    private CoreToolInvoker $invoker;

    protected function setUp(): void
    {
        $registry = new Registry();
        $registry->registerTool(new Tool('ping', null, ['type' => 'object'], null, null), PingTool::class);
        $this->delegation = new FakeDelegation();
        $this->invoker = new CoreToolInvoker(new LocalToolCaller($registry), $this->delegation);
    }

    public function testAToolOfTheServerIsCalled(): void
    {
        self::assertTrue($this->invoker->allows('ping'));
        self::assertFalse($this->invoker->allows('pong'));
        self::assertSame(['pong' => true], $this->invoker->invoke('ping', []));
    }

    public function testAnUnknownToolIsAFailureTheScriptReceives(): void
    {
        $this->expectException(ToolCallFailed::class);

        $this->invoker->invoke('pong', []);
    }

    public function testARevokedDelegationStopsBeforeTheTool(): void
    {
        $this->delegation->revoked = true;

        $this->expectException(ToolCallFailed::class);
        $this->expectExceptionMessage('delegation_revoked');

        $this->invoker->invoke('ping', []);
    }
}

final class PingTool
{
    /**
     * @return array<string, bool>
     */
    public function __invoke(): array
    {
        return ['pong' => true];
    }
}
