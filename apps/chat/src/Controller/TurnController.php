<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderModels;
use Emagma\SyliusAssistantTestChat\Conversation\ConversationStore;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Emagma\SyliusAssistantTestChat\Turn\TurnRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One chat turn, streamed as server-sent events while it runs: model calls
 * with their cost, tool calls, write confirmations, the answer.
 */
final class TurnController
{
    public function __construct(
        private readonly TurnRunner $runner,
        private readonly TurnContext $turn,
        private readonly ConversationStore $store,
        private readonly ProviderCatalog $catalog,
        private readonly ProviderModels $models,
        private readonly LockFactory $locks,
    ) {
    }

    #[Route('/api/conversations/{id}/turns', name: 'turn', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $token = $request->attributes->get(RequireLoginListener::TOKEN_ATTRIBUTE);
        \assert($token instanceof UserToken);

        $payload = $request->getPayload();
        $message = trim($payload->getString('message'));
        $provider = $payload->getString('provider');
        $model = $payload->getString('model');
        // The page's "auto-accept" switch: every write confirmation of the turn
        // is accepted on the spot, without the 30 s dialog.
        $autoAccept = $payload->getBoolean('auto_accept');

        try {
            $conversation = $this->store->load($id, $token->username);
            $catalogProvider = $this->catalog->get($provider);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
        if ('' === $message) {
            return new JsonResponse(['error' => 'Empty message.'], 422);
        }
        if (!$catalogProvider->hasApiKey) {
            return new JsonResponse(['error' => \sprintf('No API key for %s (set it in .project.local.ini, then castor init:env).', $catalogProvider->label)], 422);
        }
        if (!\in_array($model, $this->models->list($catalogProvider), true)) {
            return new JsonResponse(['error' => \sprintf('%s does not list the model "%s".', $catalogProvider->label, $model)], 422);
        }

        $lock = $this->locks->createLock('testchat.conversation.' . $id, ttl: 900);
        if (!$lock->acquire()) {
            return new JsonResponse(['error' => 'A turn is already running in this conversation.'], 409);
        }

        // RequireLoginListener renewed the token if needed. Release the session
        // now: the turn streams for minutes, and the browser's answer to a
        // confirmation must not wait for the session lock.
        $request->getSession()->save();

        return new StreamedResponse(function () use ($conversation, $message, $provider, $model, $token, $lock, $autoAccept): void {
            ignore_user_abort(true);
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $this->turn->start($token->accessToken, static function (string $event, array $data): void {
                    echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n\n";
                    flush();
                }, $autoAccept ? 'accept' : null);
                $this->runner->run($conversation, $message, $provider, $model);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
