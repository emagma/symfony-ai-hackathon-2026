<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection\Fixture;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * What an MCP extension's shop half does (the future CMS package): prepend its
 * lean group, its lean filters and its extension name to the plugin.
 */
final class ContributingExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param array<string, mixed> $contribution
     */
    public function __construct(
        private readonly string $alias,
        private readonly array $contribution,
    ) {
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('emagma_sylius_mcp', $this->contribution);
    }
}
