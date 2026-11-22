<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Skill\SkillCatalog;
use Emagma\SyliusAssistantTestChat\Skill\SkillStore;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The skills of the logged-in user: what the MCP server offers them, what they
 * installed in this chat, installing and removing one.
 */
final class SkillController
{
    public function __construct(
        private readonly SkillCatalog $catalog,
        private readonly SkillStore $store,
        private readonly TurnContext $turn,
    ) {
    }

    #[Route('/api/skills', name: 'skills', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return new JsonResponse(['skills' => $this->catalog->all($this->user($request))]);
    }

    #[Route('/api/skills/{name}', name: 'skill_install', requirements: ['name' => '[a-z0-9-]+'], methods: ['PUT'])]
    public function install(string $name, Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (!$this->catalog->install($user, $name)) {
            return new JsonResponse(['error' => \sprintf('The server does not offer you a skill "%s".', $name)], 404);
        }

        return new JsonResponse(['skills' => $this->catalog->all($user)]);
    }

    #[Route('/api/skills/{name}', name: 'skill_remove', requirements: ['name' => '[a-z0-9-]+'], methods: ['DELETE'])]
    public function remove(string $name, Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->store->remove($user, $name);

        return new JsonResponse(['skills' => $this->catalog->all($user)]);
    }

    /**
     * The user's name, their token handed to the MCP client for this request.
     */
    private function user(Request $request): string
    {
        $token = $request->attributes->get(RequireLoginListener::TOKEN_ATTRIBUTE);
        \assert($token instanceof UserToken);
        $this->turn->start($token->accessToken);

        return $token->username;
    }
}
