<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Auth\RequireLoginListener;
use Emagma\SyliusAssistantTestChat\Auth\UserToken;
use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class ChatController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PriceBook $prices,
    ) {
    }

    #[Route('/', name: 'chat', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $token = $request->attributes->get(RequireLoginListener::TOKEN_ATTRIBUTE);
        \assert($token instanceof UserToken);

        // Bust the browser cache of the hand-written assets on every change.
        $version = static fn (string $file): int => (int) @filemtime(\dirname(__DIR__, 2) . '/public/' . $file);

        return new Response($this->twig->render('chat.html.twig', [
            'username' => $token->username,
            // Without a price source, the page shows tokens only: no cost, no price.
            'pricing' => $this->prices->isConfigured(),
            'js_version' => $version('app.js'),
            'format_version' => $version('format.js'),
            'css_version' => $version('app.css'),
        ]));
    }
}
