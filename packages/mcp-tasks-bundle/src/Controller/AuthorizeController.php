<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Controller;

use Emagma\McpTasksBundle\Delegation\OfflineTokenDelegation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The return from the identity provider. The page says one thing: go back to the conversation.
 *
 * @internal
 */
final readonly class AuthorizeController
{
    public function __construct(
        private OfflineTokenDelegation $delegation,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $code = $request->query->getString('code');
        $state = $request->query->getString('state');
        $done = '' !== $code && '' !== $state && $this->delegation->complete($code, $state);
        // The check went through the firewall's handler: nothing of it must outlive this page.
        $this->delegation->release();

        $message = $done
            ? 'The assistant may now run your background tasks while you are away. Go back to the conversation and launch the task again.'
            : 'The authorization was not recorded: the link expired, or it was opened with another account. Ask for the task to be launched again to get a new link.';

        return new Response(
            '<!doctype html><meta charset="utf-8"><title>Background tasks</title><p style="font:16px/1.5 system-ui;max-width:32em;margin:4em auto">' . htmlspecialchars($message) . '</p>',
            $done ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
            ['Cache-Control' => 'no-store'],
        );
    }
}
