<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle;

use Emagma\McpSyliusEventsBundle\Webhook\ShopEventConsumer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings its own wiring as prepended defaults: the signed webhook `sylius`, the Doctrine mapping and the migrations.
 */
final class EmagmaMcpSyliusEventsBundle extends AbstractBundle
{
    /** The service that checks the signature of the shop's webhooks. */
    public const string REQUEST_PARSER = 'emagma_mcp_sylius_events.request_parser';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('framework')) {
            // The shop signs with this secret; Symfony Webhook refuses anything else (route /webhook/sylius).
            $builder->prependExtensionConfig('framework', [
                'webhook' => ['routing' => [ShopEventConsumer::WEBHOOK => [
                    'service' => self::REQUEST_PARSER,
                    'secret' => '%env(MCP_EVENT_WEBHOOK_SECRET)%',
                ]]],
            ]);
        }

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EmagmaMcpSyliusEventsBundle' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $this->getPath() . '/src/Entity',
                            'prefix' => 'Emagma\\McpSyliusEventsBundle\\Entity',
                            'alias' => 'EmagmaMcpSyliusEvents',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Emagma\\McpSyliusEventsBundle\\Migrations' => $this->getPath() . '/migrations'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
