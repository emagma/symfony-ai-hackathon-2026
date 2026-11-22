<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Command;

use Emagma\SyliusAssistantTestChat\Auth\AuthorizationServerDiscovery;
use Emagma\SyliusAssistantTestChat\Elicitation\UiElicitationCallback;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Mcp\Schema\Content\TextContent;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls one MCP tool through the chat's own client stack, without a model:
 * proves the connection, the user's grants and the confirmation path.
 *
 * Logs in with the `dev-cli` password grant - a command line has no browser.
 * The chat itself never does: its users log in through Keycloak.
 */
#[AsCommand(name: 'testchat:mcp:call', description: 'Call one MCP tool as a demo user (diagnostic, no LLM)')]
final class McpCallCommand
{
    public function __construct(
        #[Autowire(service: 'mcp.client.sylius.server.shop')]
        private readonly ServerConnectionInterface $connection,
        private readonly TurnContext $turn,
        private readonly UiElicitationCallback $elicitation,
        private readonly AuthorizationServerDiscovery $discovery,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Tool name, e.g. search_products')]
        string $tool,
        #[Argument('Arguments as a JSON object')]
        string $arguments = '{}',
        #[Option('Keycloak demo user (password = username)')]
        string $user = 'admin',
        #[Option('Answer to a write confirmation: accept or decline')]
        string $answer = 'decline',
    ): int {
        /** @var array<string, mixed> $args */
        $args = json_decode($arguments, true, 512, \JSON_THROW_ON_ERROR);

        $this->turn->start($this->passwordGrant($user), presetAnswer: $answer);

        $result = $this->connection->callTool($tool, $args);

        foreach ($this->elicitation->pullDecisions() as $decision) {
            $io->note(\sprintf('Write confirmation answered: %s', $decision->outcome()));
        }

        $text = implode("\n", array_map(
            static fn (object $content): string => $content instanceof TextContent && \is_string($content->text) ? $content->text : json_encode($content, \JSON_THROW_ON_ERROR),
            $result->content,
        ));
        $io->writeln($text);

        return $result->isError ? 1 : 0;
    }

    private function passwordGrant(string $user): string
    {
        $token = $this->httpClient->request('POST', $this->discovery->discover()->tokenEndpoint, [
            'body' => ['grant_type' => 'password', 'client_id' => 'dev-cli', 'username' => $user, 'password' => $user, 'scope' => 'openid'],
        ])->toArray()['access_token'] ?? null;

        return \is_string($token) ? $token : throw new \RuntimeException('Keycloak returned no access token.');
    }
}
