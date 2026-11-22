<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Tracing;

use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnTracer::class)]
final class TurnTracerTest extends TestCase
{
    /**
     * The tree Langfuse shows for a turn: generations and the client tool span
     * under chat.turn, the human's answer under the tool, and the tool's
     * traceparent pointing at the tool span so the server nests there too.
     */
    public function testATurnIsOneTraceWithItsGenerationsToolsAndConfirmation(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new BatchSpanProcessor($exporter, Clock::getDefault(), autoFlush: false));
        $tracer = new TurnTracer($provider);

        $tracer->beginTurn('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', 'admin', 'anthropic', 'claude-haiku-4-5', 'Create a customer group');
        $tracer->generation('anthropic', 'claude-haiku-4-5', microtime(true), ['input' => 1000, 'output' => 50, 'total' => 1050]);
        $traceparent = $tracer->beginTool('shop_create_customer_group', ['code' => 'vip']);
        $tracer->elicitation('Confirm customer group create on "vip"', microtime(true), 'auto_accept', 30_000);
        $tracer->endTool(null);
        $tracer->endTurn('Group created.', null, 0.00125);
        $provider->forceFlush();

        /** @var array<string, ImmutableSpan> $spans */
        $spans = [];
        foreach ($exporter->getSpans() as $span) {
            \assert($span instanceof ImmutableSpan);
            $spans[$span->getName()] = $span;
        }

        $turn = $spans['chat.turn'];
        $tool = $spans['mcp.tools/call shop_create_customer_group'];
        self::assertSame($turn->getSpanId(), $spans['llm claude-haiku-4-5']->getParentSpanId());
        self::assertSame($turn->getSpanId(), $tool->getParentSpanId());
        self::assertSame($tool->getSpanId(), $spans['ui.elicitation']->getParentSpanId());
        self::assertSame(\sprintf('00-%s-%s-01', $tool->getTraceId(), $tool->getSpanId()), $traceparent);

        $generation = $spans['llm claude-haiku-4-5']->getAttributes();
        self::assertSame('generation', $generation->get('langfuse.observation.type'));
        self::assertSame('{"input":1000,"output":50,"total":1050}', $generation->get('langfuse.observation.usage_details'));
        self::assertNull($generation->get('langfuse.observation.cost_details'), 'Langfuse prices it from its own table');
        self::assertSame('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', $generation->get('langfuse.session.id'), 'every span carries the trace attributes');
        self::assertSame('auto_accept', $spans['ui.elicitation']->getAttributes()->get('langfuse.observation.metadata.outcome'));
    }
}
