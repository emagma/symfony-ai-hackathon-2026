<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Turn;

use Emagma\SyliusAssistantTestChat\Mcp\AppTools;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Emagma\SyliusAssistantTestChat\Turn\TurnToolbox;
use Mcp\Schema\Tool;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\Platform\Result\ToolCall;

#[CoversClass(TurnToolbox::class)]
final class TurnToolboxTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $events = [];

    public function testAToolThatOpensAScreenHandsThePageTheScreenAndTheModelItsData(): void
    {
        $call = new ToolCall('call-1', 'shop_open_screen', ['screen' => 'address', 'params' => ['customerId' => '1']]);
        $shown = ['html' => '<div class="screen">…</div>', 'ok' => true, 'data' => ['subject' => 'Default address of customer #1']];

        $result = $this->toolbox($call, $shown)->execute($call);

        self::assertSame(['ok' => true, 'data' => ['subject' => 'Default address of customer #1']], $result->getResult(), 'the markup is the user\'s, not the model\'s');

        [$event, $data] = $this->events[1];
        self::assertSame('tool_call', $event);
        self::assertSame('done', $data['status']);
        self::assertTrue($data['ok']);
        self::assertSame(['uri' => 'ui://screens', 'arguments' => $call->getArguments(), 'result' => $shown], $data['app']);
    }

    public function testAnyOtherToolIsReportedAndReturnedAsItIs(): void
    {
        $call = new ToolCall('call-2', 'shop_search_products', []);
        $found = ['items' => [], 'total' => 0, 'html' => 'a field of the shop, not a screen'];

        $result = $this->toolbox($call, $found)->execute($call);

        self::assertSame($found, $result->getResult());
        self::assertArrayNotHasKey('app', $this->events[1][1]);
    }

    public function testAToolTheUserIsNotServedLetsTheModelSaySoInsteadOfFailingTheTurn(): void
    {
        $call = new ToolCall('call-3', 'shop_upsert_customer_groups', ['items' => []]);
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('execute')->willThrowException(ToolNotFoundException::notFoundForToolCall($call));
        $turn = new TurnContext();
        $turn->start('user-jwt', function (string $event, array $data): void {
            /** @var array<string, mixed> $data */
            $this->events[] = [$event, $data];
        });

        $result = (new TurnToolbox($inner, $turn, new TurnTracer(new NoopTracerProvider())))->execute($call);

        self::assertIsString($result->getResult());
        self::assertStringContainsString('cannot be done with their rights', $result->getResult());
        self::assertSame('error', $this->events[1][1]['status']);
    }

    private function toolbox(ToolCall $call, mixed $value): TurnToolbox
    {
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('execute')->willReturn(new ToolResult($call, $value));

        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('getTools')->willReturn([
            new Tool('open_screen', null, ['type' => 'object'], null, null, meta: ['ui' => ['resourceUri' => 'ui://screens', 'visibility' => ['model', 'app']]]),
            new Tool('search_products', null, ['type' => 'object'], null, null),
        ]);

        $turn = new TurnContext();
        $turn->start('user-jwt', function (string $event, array $data): void {
            /** @var array<string, mixed> $data */
            $this->events[] = [$event, $data];
        });

        return new TurnToolbox($inner, $turn, new TurnTracer(new NoopTracerProvider()), new AppTools($connection));
    }
}
