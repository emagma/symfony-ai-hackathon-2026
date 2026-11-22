<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Elicitation\ElicitationBroker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The browser's answer to a write confirmation. No session here on purpose
 * (see RequireLoginListener): the unguessable id is the credential.
 */
final class ElicitationController
{
    public function __construct(
        private readonly ElicitationBroker $broker,
    ) {
    }

    #[Route('/api/elicitations/{id}', name: 'elicitation_answer', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $action = $request->getPayload()->getString('action');

        return $this->broker->answer($id, $action)
            ? new Response(status: 204)
            : new JsonResponse(['error' => 'No confirmation waits on this id (answered, expired or unknown).'], 404);
    }
}
