<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Mcp\AppTools;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Mcp\Schema\Content\TextResourceContents;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The chat as an MCP Apps host: what a screen shown in the conversation needs
 * from the server, asked with the user's own token.
 *
 * - the document of the app (a `ui://` resource), which the page puts in a
 *   sandboxed iframe;
 * - the tool calls the screen makes, limited to the tools of its own app. A
 *   write still asks the human: the confirmation travels on the same stream
 *   as for a turn.
 */
final class AppController
{
    public function __construct(
        #[Autowire(service: 'mcp.client.sylius.server.shop')]
        private readonly ServerConnectionInterface $connection,
        private readonly AppTools $apps,
        private readonly TurnContext $turn,
    ) {
    }

    #[Route('/api/apps/resource', name: 'app_resource', methods: ['GET'])]
    public function resource(Request $request): Response
    {
        $uri = $request->query->getString('uri');
        if (!str_starts_with($uri, 'ui://')) {
            return new JsonResponse(['error' => 'Not a ui:// resource.'], 422);
        }

        $this->turn->start(self::token($request)->accessToken);

        try {
            foreach ($this->connection->readResource($uri)->contents as $content) {
                if ($content instanceof TextResourceContents) {
                    return new JsonResponse(['uri' => $uri, 'html' => $content->text]);
                }
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        return new JsonResponse(['error' => 'The server returned no document for this resource.'], 404);
    }

    #[Route('/api/apps/call', name: 'app_call', methods: ['POST'])]
    public function call(Request $request): Response
    {
        $payload = $request->getPayload();
        $uri = $payload->getString('uri');
        $name = $payload->getString('name');
        $arguments = [];
        foreach ($payload->all('arguments') as $key => $value) {
            $arguments[(string) $key] = $value;
        }
        $autoAccept = $payload->getBoolean('auto_accept');
        $token = self::token($request);

        $this->turn->start($token->accessToken);
        if (!$this->apps->belongsTo($name, $uri)) {
            return new JsonResponse(['error' => \sprintf('The tool "%s" is not one of the app %s.', $name, $uri)], 403);
        }

        // As for a turn: the call may wait for a click, and the answer to the
        // confirmation must not wait for the session lock.
        $request->getSession()->save();

        return new StreamedResponse(function () use ($token, $name, $arguments, $autoAccept): void {
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $emit = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n\n";
                flush();
            };

            $this->turn->start($token->accessToken, $emit, $autoAccept ? 'accept' : null);

            try {
                $emit('result', $this->connection->callTool($name, $arguments)->jsonSerialize());
            } catch (\Throwable $e) {
                $emit('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function token(Request $request): UserToken
    {
        $token = $request->attributes->get(RequireLoginListener::TOKEN_ATTRIBUTE);
        \assert($token instanceof UserToken);

        return $token;
    }
}
