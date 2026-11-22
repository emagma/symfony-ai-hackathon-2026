<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds `Vary: Prefer` to the API responses, since the preference changes the representation of a URL,
 * and signs them with `Sylius-Mcp-Plugin: 1`.
 *
 * @internal
 */
final class LeanVaryListener
{
    /**
     * Tells the MCP server the plugin is installed. Without it the server
     * gets full payloads and its extra filters are silently ignored: it warns
     * once when this header is missing.
     */
    public const string PRESENCE_HEADER = 'Sylius-Mcp-Plugin';

    private const string PATH_PREFIX = '/api/';

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set(self::PRESENCE_HEADER, '1');

        $vary = $response->getVary();
        foreach ($vary as $name) {
            if (0 === strcasecmp($name, 'Prefer')) {
                return;
            }
        }

        $response->setVary([...$vary, 'Prefer'], true);
    }
}
