<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * What a bundle built on the core prepends, from its own prepend step: defaults the host's configuration overrides.
 * Each method does nothing when the bundle it configures is not registered.
 */
final class McpCoreConfig
{
    /** The configuration alias of the core. */
    public const string ALIAS = 'emagma_mcp_core';

    private function __construct()
    {
    }

    /**
     * Adds to the `mcp.servers` entry (symfony/mcp-bundle) of the server the core attaches to, `default` unless
     * `emagma_mcp_core.server` names another: typically the namespace of the bundle's tools.
     *
     * @param array<string, mixed> $config
     */
    public static function prependServerConfig(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasExtension('mcp')) {
            return;
        }

        $server = 'default';
        foreach ($container->getExtensionConfig(self::ALIAS) as $core) {
            if (\is_string($core['server'] ?? null)) {
                $server = $core['server'];
            }
        }

        $container->prependExtensionConfig('mcp', ['servers' => [$server => $config]]);
    }

    /**
     * The domain of the grants (`mcp:<domain>:<resource>:<action>`) of the tools under a namespace prefix.
     * The longest prefix wins.
     */
    public static function prependPermissionDomain(ContainerBuilder $container, string $namespace, string $domain): void
    {
        if ($container->hasExtension(self::ALIAS)) {
            $container->prependExtensionConfig(self::ALIAS, ['permission_domains' => [$namespace => $domain]]);
        }
    }

    /**
     * What the model is told about the fields marked `#[Untrusted]`, in the words of the backend that knows who writes them.
     */
    public static function prependUntrustedContentNotice(ContainerBuilder $container, string $notice): void
    {
        if ($container->hasExtension(self::ALIAS)) {
            $container->prependExtensionConfig(self::ALIAS, ['untrusted_content_notice' => $notice]);
        }
    }
}
