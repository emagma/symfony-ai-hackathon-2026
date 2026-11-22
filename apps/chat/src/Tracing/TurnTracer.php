<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One Langfuse trace per chat turn:
 *
 *   chat.turn (agent)
 *   ├─ llm <model> (generation: usage + cost)      × each model call
 *   └─ mcp.tools/call <tool> (tool)                 × each tool call
 *      ├─ tool:<tool>, mcp.elicitation               ← the MCP server, via traceparent
 *      └─ ui.elicitation                             ← the human's answer
 *
 * The chat owns the trace: its session is the conversation, its user the
 * logged-in user. Langfuse v4 does not propagate those to child spans, so
 * every span carries them. Parents are passed explicitly; no OTel context is
 * made current.
 */
final class TurnTracer implements ResetInterface
{
    private const MAX_PAYLOAD = 8000;

    private readonly TracerInterface $tracer;

    private ?SpanInterface $turn = null;

    private ?SpanInterface $tool = null;

    /** @var array<string, string|list<string>> */
    private array $traceAttributes = [];

    public function __construct(
        #[Autowire(service: 'chat.tracer_provider')]
        TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('emagma/sylius-assistant-testchat');
    }

    public function beginTurn(string $conversationId, string $user, string $provider, string $model, string $message): void
    {
        $this->traceAttributes = [
            'langfuse.trace.name' => 'chat.turn',
            'langfuse.session.id' => $conversationId,
            'langfuse.user.id' => $user,
            'langfuse.trace.tags' => ['testchat', 'provider:' . $provider, 'model:' . $model],
        ];

        $this->turn = $this->start('chat.turn', 'agent', null, $message);
    }

    public function endTurn(?string $answer, ?string $error, float $costUsd): void
    {
        if (null === $this->turn) {
            return;
        }

        $this->turn->setAttribute('langfuse.observation.metadata.turn_cost_usd', $costUsd);
        self::finish($this->turn, $answer, $error);
        $this->turn = $this->tool = null;
    }

    /**
     * A model call that has completed: opened at its real start time.
     *
     * Usage only, by Langfuse usage type: Langfuse prices it from its own model
     * table - the one the chat's counter reads too (PriceBook).
     *
     * @param array<string, int> $usage {input, input_cached_tokens?, input_cache_creation?, output, total}
     */
    public function generation(string $provider, string $model, float $startedAt, array $usage, ?string $error = null): void
    {
        $span = $this->start('llm ' . $model, 'generation', $this->turn, null, $startedAt);
        $span->setAttribute('gen_ai.system', $provider)
            ->setAttribute('gen_ai.request.model', $model)
            ->setAttribute('gen_ai.response.model', $model)
            ->setAttribute('gen_ai.operation.name', 'chat')
            ->setAttribute('langfuse.observation.usage_details', (string) json_encode($usage));

        self::finish($span, null, $error);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string the W3C traceparent to send with the tool's MCP requests
     */
    public function beginTool(string $name, array $arguments): ?string
    {
        if (null === $this->turn) {
            return null;
        }

        $this->tool = $this->start('mcp.tools/call ' . $name, 'tool', $this->turn, $arguments);
        $this->tool->setAttribute('langfuse.observation.metadata.tool_name', $name);

        return self::traceparent($this->tool);
    }

    public function endTool(?string $error): ?string
    {
        if (null !== $this->tool) {
            self::finish($this->tool, null, $error);
            $this->tool = null;
        }

        return $this->turnTraceparent();
    }

    /**
     * The human's answer to a write confirmation, under the tool that asked.
     */
    public function elicitation(string $message, float $startedAt, string $outcome, int $waitedMs): void
    {
        $span = $this->start('ui.elicitation', 'span', $this->tool ?? $this->turn, $message, $startedAt);
        $span->setAttribute('langfuse.observation.metadata.outcome', $outcome)
            ->setAttribute('langfuse.observation.metadata.waited_ms', $waitedMs);
        self::finish($span, $outcome, null);
    }

    /**
     * The traceparent of the turn itself: the MCP handshake (initialize,
     * tools/list) made outside any tool call hangs under the turn.
     */
    public function turnTraceparent(): ?string
    {
        return null === $this->turn ? null : self::traceparent($this->turn);
    }

    public function reset(): void
    {
        if (null !== $this->turn) {
            $this->endTurn(null, 'The request ended before the turn did.', 0.0);
        }
        $this->traceAttributes = [];
    }

    private function start(string $name, string $type, ?SpanInterface $parent, mixed $input, ?float $startedAt = null): SpanInterface
    {
        $builder = $this->tracer->spanBuilder('' !== $name ? $name : 'span')
            ->setParent(null === $parent ? false : $parent->storeInContext(Context::getRoot()))
            ->setAttributes($this->traceAttributes)
            ->setAttribute('langfuse.observation.type', $type);

        if (null !== $input) {
            $builder->setAttribute('langfuse.observation.input', self::payload($input));
        }
        if (null !== $startedAt) {
            $builder->setStartTimestamp((int) ($startedAt * 1_000_000_000));
        }

        return $builder->startSpan();
    }

    private static function finish(SpanInterface $span, mixed $output, ?string $error): void
    {
        if (null !== $output) {
            $span->setAttribute('langfuse.observation.output', self::payload($output));
        }
        if (null !== $error) {
            $span->setAttribute('langfuse.observation.level', 'ERROR')
                ->setAttribute('langfuse.observation.status_message', $error)
                ->setStatus(StatusCode::STATUS_ERROR, $error);
        }
        $span->end();
    }

    private static function traceparent(SpanInterface $span): string
    {
        $context = $span->getContext();

        // Flags 01 (sampled): the server samples ParentBased, a 00 would drop its spans.
        return \sprintf('00-%s-%s-01', $context->getTraceId(), $context->getSpanId());
    }

    private static function payload(mixed $value): string
    {
        $json = \is_string($value) ? $value : (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \strlen($json) > self::MAX_PAYLOAD ? mb_strcut($json, 0, self::MAX_PAYLOAD) . '…[truncated]' : $json;
    }
}
