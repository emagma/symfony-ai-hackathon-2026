<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Emagma\SyliusAssistantTestChat\Mcp\AppTools;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The MCP toolbox as the turn sees it: each tool call is reported to the UI
 * as it starts and ends.
 */
final class TurnToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly ToolboxInterface $inner,
        private readonly TurnContext $turn,
        private readonly TurnTracer $tracer,
        private readonly ?AppTools $apps = null,
    ) {
    }

    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $started = microtime(true);
        $this->turn->emit('tool_call', ['id' => $toolCall->getId(), 'name' => $toolCall->getName(), 'status' => 'started', 'arguments' => $toolCall->getArguments()]);

        // The tool's MCP requests carry its span: the server's spans nest under it.
        $this->turn->setTraceparent($this->tracer->beginTool($toolCall->getName(), $toolCall->getArguments()));

        try {
            $result = $this->inner->execute($toolCall);
        } catch (ToolNotFoundException $e) {
            // A tool the user's rights do not serve, which the model named anyway: it reads why and answers,
            // the turn goes on instead of failing on an error the user cannot act on.
            $this->turn->setTraceparent($this->tracer->endTool($e->getMessage()));
            $this->turn->emit('tool_call', ['id' => $toolCall->getId(), 'name' => $toolCall->getName(), 'status' => 'error', 'error' => 'not available to this user', 'duration_ms' => self::since($started)]);

            return new ToolResult($toolCall, \sprintf('No tool "%s" is available to you: the rights of the logged-in user do not include it. Do not try another tool for this; tell the user it cannot be done with their rights.', $toolCall->getName()));
        } catch (\Throwable $e) {
            $this->turn->setTraceparent($this->tracer->endTool($e->getMessage()));
            $this->turn->emit('tool_call', ['id' => $toolCall->getId(), 'name' => $toolCall->getName(), 'status' => 'error', 'error' => $e->getMessage(), 'duration_ms' => self::since($started)]);

            throw $e;
        }

        $this->turn->setTraceparent($this->tracer->endTool(null));
        $uri = $this->apps?->uriOf($toolCall->getName());
        $value = $result->getResult();

        $this->turn->emit('tool_call', [
            'id' => $toolCall->getId(),
            'name' => $toolCall->getName(),
            'status' => 'done',
            'duration_ms' => self::since($started),
        ] + self::verdict($result) + (null === $uri ? [] : ['app' => ['uri' => $uri, 'arguments' => $toolCall->getArguments(), 'result' => $value]]));

        // A tool that opens a screen (MCP Apps): the page shows the screen, the
        // model reads what it shows - not the markup, which is the user's.
        if (null !== $uri && \is_array($value) && \array_key_exists('html', $value)) {
            unset($value['html']);

            return new ToolResult($toolCall, $value, $result->getSources());
        }

        return $result;
    }

    /**
     * The write envelope's `ok`, when the server's structured result carries
     * one: the page can then show a refused write as such without parsing the
     * text the model reads. A read, or a result the bridge rendered as text,
     * says nothing.
     *
     * @return array{ok?: bool}
     */
    private static function verdict(ToolResult $result): array
    {
        $value = $result->getResult();

        return \is_array($value) && \is_bool($value['ok'] ?? null) ? ['ok' => $value['ok']] : [];
    }

    private static function since(float $started): int
    {
        return (int) ((microtime(true) - $started) * 1000);
    }
}
