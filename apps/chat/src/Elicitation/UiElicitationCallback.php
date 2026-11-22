<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Elicitation;

use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/**
 * Answers the server's write confirmations (MCP elicitation) through the UI.
 *
 * Runs synchronously inside the turn: it shows the question in the browser
 * (an SSE event), then waits for the click - 30 s, after which it accepts on
 * the user's behalf. Accepting means sending `confirm: true`: an accept with
 * no content is treated as a refusal by the server.
 */
final class UiElicitationCallback implements ElicitationCallbackInterface
{
    /** @var list<Decision> */
    private array $decisions = [];

    public function __construct(
        private readonly ElicitationBroker $broker,
        private readonly TurnContext $turn,
        private readonly ?TurnTracer $tracer = null,
        private readonly float $timeout = TurnContext::ELICITATION_TIMEOUT,
    ) {
    }

    public function __invoke(ElicitRequest $request): ElicitResult
    {
        $started = microtime(true);
        $decision = $this->decide($request, $started);
        $this->decisions[] = $decision;
        $this->tracer?->elicitation($request->message, $started, $decision->outcome(), $decision->waitedMs);

        $this->turn->emit('elicitation_resolved', ['action' => $decision->action, 'outcome' => $decision->outcome(), 'waited_ms' => $decision->waitedMs]);

        return 'accept' === $decision->action
            ? new ElicitResult(ElicitAction::Accept, $this->acceptance($request))
            : new ElicitResult(ElicitAction::from($decision->action));
    }

    /**
     * Every confirmation answered since the last call, oldest first.
     *
     * @return list<Decision>
     */
    public function pullDecisions(): array
    {
        $decisions = $this->decisions;
        $this->decisions = [];

        return $decisions;
    }

    private function decide(ElicitRequest $request, float $started): Decision
    {
        $preset = $this->turn->presetAnswer();
        if (null !== $preset) {
            return new Decision($preset, Decision::PRESET, 0);
        }

        $id = $this->broker->open();
        $this->turn->emit('elicitation', [
            'id' => $id,
            'message' => $request->message,
            'fields' => array_map(
                static fn (object $field): array => ['title' => $field->title ?? null, 'description' => $field->description ?? null],
                $request->requestedSchema->properties ?? [],
            ),
            'timeout_s' => $this->timeout,
            'deadline' => (int) (($started + $this->timeout) * 1000),
        ]);

        $action = $this->broker->await($id, $this->timeout);
        $waited = (int) ((microtime(true) - $started) * 1000);

        return null === $action
            ? new Decision('accept', Decision::AUTO, $waited)
            : new Decision($action, Decision::MANUAL, $waited);
    }

    /**
     * Every boolean the server asks for is answered `true` - the confirmation
     * itself. The plugin's write guard asks for exactly one: `confirm`.
     *
     * @return array<string, mixed>
     */
    private function acceptance(ElicitRequest $request): array
    {
        $content = [];
        foreach ($request->requestedSchema->properties ?? [] as $name => $field) {
            if ($field instanceof BooleanSchemaDefinition) {
                $content[$name] = true;
            }
        }

        return $content + ['confirm' => true];
    }
}
