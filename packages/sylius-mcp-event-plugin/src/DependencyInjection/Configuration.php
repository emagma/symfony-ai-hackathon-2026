<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The plugin's configuration: the webhook URL and secret, and the transport it leaves through.
 *
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('emagma_sylius_mcp_event');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('url')
                    ->info('Where the MCP server receives the events, e.g. http://mcp-server/webhook/sylius. `MCP_EVENT_WEBHOOK_URL` by default; empty sends nothing.')
                    ->defaultValue('%env(default::MCP_EVENT_WEBHOOK_URL)%')
                ->end()
                ->scalarNode('secret')
                    ->info('The secret shared with the MCP server, which signs every event. `MCP_EVENT_WEBHOOK_SECRET` by default; empty sends nothing.')
                    ->defaultValue('%env(default::MCP_EVENT_WEBHOOK_SECRET)%')
                ->end()
                ->scalarNode('transport')
                    ->info('The Messenger transport the webhooks are sent through, so that the shop never waits for the server. Sylius\'s `main` by default.')
                    ->cannotBeEmpty()
                    ->defaultValue('main')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
