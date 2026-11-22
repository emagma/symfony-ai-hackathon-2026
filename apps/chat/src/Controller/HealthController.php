<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok', headers: ['Content-Type' => 'text/plain']);
    }
}
