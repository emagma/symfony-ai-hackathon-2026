<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Emagma\SyliusAssistantTestChat\Mcp\AppTools;
use Emagma\SyliusAssistantTestChat\Mcp\FilteredToolset;
use Emagma\SyliusAssistantTestChat\Tracing\TurnTracer;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the agent of one turn: the platform and model the user picked, the
 * MCP tools the logged-in user is granted. Built by hand rather than declared
 * under `ai.agent`, whose platform and model are fixed in configuration.
 */
final class AgentFactory
{
    public const MAX_TOOL_CALLS = 15;

    /**
     * The Anthropic bridge stops an answer at 1000 output tokens unless told otherwise: a tool call with
     * a long argument list was cut there and reached the MCP server without its arguments.
     */
    public const ANTHROPIC_MAX_OUTPUT_TOKENS = 8192;

    /** @var array<string, PlatformInterface> */
    private readonly array $platforms;

    public function __construct(
        #[Autowire(service: 'ai.platform.openai')]
        PlatformInterface $openai,
        #[Autowire(service: 'ai.platform.anthropic')]
        PlatformInterface $anthropic,
        #[Autowire(service: 'ai.platform.mistral')]
        PlatformInterface $mistral,
        #[Autowire(service: 'ai.platform.generic.qwen')]
        PlatformInterface $qwen,
        private readonly FilteredToolset $toolset,
        private readonly TurnContext $turn,
        private readonly LoggerInterface $logger,
        private readonly TurnTracer $tracer,
        private readonly ?AppTools $apps = null,
    ) {
        $this->platforms = ['openai' => $openai, 'anthropic' => $anthropic, 'mistral' => $mistral, 'qwen' => $qwen];
    }

    /**
     * The options of every model call of a turn. Only Anthropic needs one: the other bridges set no limit
     * of their own, and the OpenAI one does not translate the canonical option.
     *
     * @return array<string, mixed>
     */
    public static function callOptions(string $provider): array
    {
        return 'anthropic' === $provider ? [PlatformOptions::MAX_OUTPUT_TOKENS => self::ANTHROPIC_MAX_OUTPUT_TOKENS] : [];
    }

    public function create(string $provider, string $model): Agent
    {
        if ('' === $model) {
            throw new \InvalidArgumentException('No model given.');
        }
        $platform = $this->platforms[$provider] ?? throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $provider));

        return new Agent(
            $platform,
            $model,
            name: 'testchat',
            toolbox: new TurnToolbox(new McpToolbox($this->toolset, logger: $this->logger), $this->turn, $this->tracer, $this->apps),
            maxToolCalls: self::MAX_TOOL_CALLS,
        );
    }
}
