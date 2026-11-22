<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;

/**
 * Loads the services, passes the configuration to the relay and routes the webhook message to its transport.
 *
 * @internal
 */
final class EmagmaSyliusMcpEventExtension extends Extension implements PrependExtensionInterface
{
    public function getAlias(): string
    {
        return 'emagma_sylius_mcp_event';
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }
        /** @var array{transport: string} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));

        // Asynchronous: a server that is slow or down must not slow down a registration.
        $container->prependExtensionConfig('framework', [
            'messenger' => ['routing' => [SendWebhookMessage::class => $config['transport']]],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config')))->load('services.php');

        /** @var array{url: string|null, secret: string|null, transport: string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->getDefinition('emagma_sylius_mcp_event.relay')
            ->replaceArgument(1, $config['url'])
            ->replaceArgument(2, $config['secret'])
        ;
    }
}
