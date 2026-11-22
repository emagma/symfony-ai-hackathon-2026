<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;

/**
 * What the plugin publishes about the shop. Exceptions propagate: a 404 means a plugin
 * older than the endpoint, and each command decides what that implies.
 *
 * @internal
 */
final readonly class CapabilitiesReader
{
    /** For messages only: requests use {@see Resource::PluginCapabilities}. */
    public const string PATH = 'sylius-mcp/capabilities';

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{extensions: array<string, string>, sylius: ?string} extension name => version ('' when the shop gives none); sylius null on a plugin that does not publish it
     */
    public function read(): array
    {
        $capabilities = $this->client->getDecoded(Route::collection(Resource::PluginCapabilities));

        $extensions = [];
        foreach (\is_array($capabilities['extensions'] ?? null) ? $capabilities['extensions'] : [] as $key => $value) {
            // {"an_extension": "1.1.6"}, or a plain list of names.
            if (\is_string($key)) {
                $extensions[$key] = \is_scalar($value) ? (string) $value : '';
            } elseif (\is_string($value)) {
                $extensions[$value] = '';
            }
        }
        $sylius = $capabilities['sylius'] ?? null;

        return ['extensions' => $extensions, 'sylius' => \is_string($sylius) && '' !== $sylius ? $sylius : null];
    }
}
