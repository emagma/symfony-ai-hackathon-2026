<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Call;

use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpCoreBundle\Call\LocalToolCallFailed;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalToolCaller::class)]
#[CoversClass(LocalToolCallFailed::class)]
final class LocalToolCallerTest extends TestCase
{
    private LocalToolCaller $caller;

    protected function setUp(): void
    {
        $registry = new Registry();
        $registry->registerTool(
            new Tool('search', null, ['type' => 'object', 'properties' => ['page' => ['type' => 'integer']], 'required' => ['page']], null, null),
            LocalSearchTool::class,
        );
        $registry->registerTool(
            new Tool('write', null, ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'confirm' => ['type' => 'boolean']], 'required' => ['code']], null, null),
            LocalWriteTool::class,
        );
        $registry->registerTool(
            new Tool('broken', null, ['type' => 'object'], null, null),
            LocalBrokenTool::class,
        );
        $this->caller = new LocalToolCaller($registry);
    }

    public function testAToolIsCalledWithItsArgumentsAndAnswersPlainData(): void
    {
        self::assertTrue($this->caller->has('search'));
        self::assertFalse($this->caller->has('unknown'));
        self::assertSame(['items' => [['code' => 'A']], 'page' => 2], $this->caller->call('search', ['page' => 2]));
    }

    public function testAWriteIsConfirmedByTheHost(): void
    {
        // Whoever calls from the host validated the write beforehand: the caller's own confirm is not asked.
        self::assertSame(['ok' => true, 'updated' => 'A'], $this->caller->call('write', ['code' => 'A', 'confirm' => false]));
    }

    public function testAnUnknownToolFails(): void
    {
        $this->expectException(LocalToolCallFailed::class);
        $this->expectExceptionMessage('unknown');

        $this->caller->call('unknown', []);
    }

    public function testArgumentsOutsideTheSchemaFail(): void
    {
        $this->expectException(LocalToolCallFailed::class);
        $this->expectExceptionMessage('page');

        $this->caller->call('search', ['page' => 'two']);
    }

    public function testAToolThatThrowsFails(): void
    {
        $this->expectException(LocalToolCallFailed::class);
        $this->expectExceptionMessage('The shop did not answer.');

        $this->caller->call('broken', []);
    }
}

/**
 * Tools are registered by class, as the server's are.
 */
final class LocalSearchTool
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $page): array
    {
        return ['items' => [new \ArrayObject(['code' => 'A'])], 'page' => $page];
    }
}

final class LocalWriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $code, bool $confirm = false, ?RequestContext $context = null): array
    {
        return $confirm && null === $context ? ['ok' => true, 'updated' => $code] : ['ok' => false, 'reason' => 'elicitation_unavailable_preview_only'];
    }
}

final class LocalBrokenTool
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        throw new \RuntimeException('The shop did not answer.');
    }
}
