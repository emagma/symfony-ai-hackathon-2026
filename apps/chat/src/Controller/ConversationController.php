<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Conversation\ConversationStore;
use Emagma\SyliusAssistantTestChat\Conversation\LlmCall;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The user's conversations: the list, one of them, deleting one.
 */
final class ConversationController
{
    public function __construct(
        private readonly ConversationStore $store,
        private readonly LockFactory $locks,
    ) {
    }

    /**
     * The logged-in user's conversations, most recently used first.
     */
    #[Route('/api/conversations', name: 'conversations', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return new JsonResponse(['conversations' => $this->store->list(self::user($request))]);
    }

    /**
     * Reloads a conversation: its transcript (messages and activity) and its cost.
     */
    #[Route('/api/conversations/{id}', name: 'conversation', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        try {
            $conversation = $this->store->load($id, self::user($request));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse([
            'id' => $conversation->id,
            'title' => $conversation->title(),
            'transcript' => $conversation->transcript(),
            'calls' => array_map(static fn (LlmCall $call): array => $call->toArray(), $conversation->calls()),
            'cost_usd' => $conversation->costUsd(),
            'cost_complete' => $conversation->isCostComplete(),
        ]);
    }

    #[Route('/api/conversations/{id}', name: 'conversation_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): Response
    {
        // The turn's own lock: a conversation is not deleted under a running turn.
        $lock = $this->locks->createLock('testchat.conversation.' . $id);
        if (!$lock->acquire()) {
            return new JsonResponse(['error' => 'A turn is running in this conversation.'], 409);
        }

        try {
            return $this->store->delete($id, self::user($request))
                ? new Response(status: 204)
                : new JsonResponse(['error' => 'No such conversation.'], 404);
        } catch (\InvalidArgumentException|\DomainException) {
            // Another user's conversation: as good as absent.
            return new JsonResponse(['error' => 'No such conversation.'], 404);
        } finally {
            $lock->release();
        }
    }

    private static function user(Request $request): string
    {
        $token = $request->attributes->get(RequireLoginListener::TOKEN_ATTRIBUTE);
        \assert($token instanceof UserToken);

        return $token->username;
    }
}
