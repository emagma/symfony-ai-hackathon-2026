<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\Tests\Unit;

use Emagma\SyliusMcpEventPlugin\DependencyInjection\EmagmaSyliusMcpEventExtension;
use Emagma\SyliusMcpEventPlugin\Relay\ShopEventRelay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;

#[CoversClass(EmagmaSyliusMcpEventExtension::class)]
final class ExtensionTest extends TestCase
{
    public function testTheConfigurationReachesTheRelayAndTheListenerIsTagged(): void
    {
        $container = new ContainerBuilder();

        (new EmagmaSyliusMcpEventExtension())->load([['url' => 'http://mcp-server/webhook/sylius', 'secret' => 's3cret']], $container);

        $relay = $container->getDefinition('emagma_sylius_mcp_event.relay');
        self::assertSame('http://mcp-server/webhook/sylius', $relay->getArgument(1));
        self::assertSame('s3cret', $relay->getArgument(2));
        self::assertSame('emagma_sylius_mcp_event.relay', (string) $container->getAlias(ShopEventRelay::class));
        self::assertSame(
            [['event' => 'postPersist']],
            $container->getDefinition('emagma_sylius_mcp_event.listener.customer_created')->getTag('doctrine.event_listener'),
        );
    }

    public function testTheWebhookMessageIsRoutedToTheConfiguredTransport(): void
    {
        $extension = new EmagmaSyliusMcpEventExtension();
        $container = new ContainerBuilder();
        $container->registerExtension($extension);
        $container->registerExtension(new class() extends Extension {
            public function getAlias(): string
            {
                return 'framework';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        $container->loadFromExtension('emagma_sylius_mcp_event', ['transport' => 'async']);

        $extension->prepend($container);

        self::assertSame(
            [['messenger' => ['routing' => [SendWebhookMessage::class => 'async']]]],
            $container->getExtensionConfig('framework'),
        );
    }
}
