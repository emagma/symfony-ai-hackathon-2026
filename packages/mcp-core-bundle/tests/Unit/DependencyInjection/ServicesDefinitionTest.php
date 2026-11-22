<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\DependencyInjection;

use Emagma\McpCoreBundle\Authorization\AllowAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\DenyAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\LocalToolCaller;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpCoreBundle\Caller\SecurityActorProvider;
use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use Emagma\McpCoreBundle\Journal\NullWriteJournal;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Not a full compile: catches a broken file, a renamed class and a changed argument shape in `config/services.php`.
 */
#[CoversNothing]
final class ServicesDefinitionTest extends TestCase
{
    public function testTheServicesFileLoads(): void
    {
        self::assertNotEmpty($this->container()->getDefinitions());
    }

    public function testItsServicesAreNamedAfterTheAliasAndPrivate(): void
    {
        foreach ($this->container()->getDefinitions() as $id => $definition) {
            if ('service_container' === $id) {
                continue;
            }
            self::assertStringStartsWith('emagma_mcp_core.', $id);
            self::assertFalse($definition->isPublic(), $id . ' is private');
            self::assertFalse($definition->isAutowired(), $id . ' is wired explicitly');
        }
    }

    /**
     * What the other bundles inject, and what a host aliases an interface to.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function injectedByClass(): iterable
    {
        foreach ([
            GrantsProviderInterface::class,
            DenyAllGrantsProvider::class,
            AllowAllGrantsProvider::class,
            ToolPermissionResolver::class,
            ActorProviderInterface::class,
            SecurityActorProvider::class,
            NullActorProvider::class,
            McpCallerStore::class,
            WriteJournalInterface::class,
            NullWriteJournal::class,
            SensitiveDataRedactor::class,
            ToolClassMap::class,
            LocalToolCaller::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('injectedByClass')]
    public function testWhatOtherBundlesInjectIsReachedByItsClass(string $class): void
    {
        $container = $this->container();

        self::assertTrue($container->hasAlias($class), $class . ' is an alias');
        self::assertTrue(is_a((string) $container->findDefinition($class)->getClass(), $class, true));
    }

    public function testTheGrantsAreDenyAllUnlessTheHostSaysOtherwise(): void
    {
        self::assertSame('emagma_mcp_core.grants_provider.deny_all', (string) $this->container()->getAlias(GrantsProviderInterface::class));
        self::assertSame(DenyAllGrantsProvider::class, $this->container()->getDefinition('emagma_mcp_core.grants_provider.deny_all')->getClass());
    }

    public function testTheActorComesFromTheSecurityContextByDefault(): void
    {
        self::assertSame('emagma_mcp_core.actor_provider.security', (string) $this->container()->getAlias(ActorProviderInterface::class));
        self::assertSame(SecurityActorProvider::class, $this->container()->getDefinition('emagma_mcp_core.actor_provider.security')->getClass());
    }

    public function testTheWriteConfirmationPolicyIsReappliedOnEveryEntryPoint(): void
    {
        $tags = $this->container()
            ->getDefinition('emagma_mcp_core.listener.write_confirmation_policy')
            ->getTag('kernel.event_listener')
        ;

        self::assertSame(
            ['kernel.request', 'console.command'],
            array_column($tags, 'event'),
        );
    }

    private function prepend(ContainerBuilder $container): void
    {
        $extension = (new EmagmaMcpCoreBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);
    }

    private function kernelContainer(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 3) . '/config')))->load('services.php');

        return $container;
    }

    public function testABundleGivesDefaultsToTheServerTheCoreAttachesTo(): void
    {
        $container = $this->kernelContainer();
        McpCoreConfig::prependServerConfig($container, ['instructions' => 'x']);
        self::assertSame([], $container->getExtensionConfig('mcp'), 'no McpBundle: nothing');

        $container->registerExtension(new class() extends \Symfony\Component\DependencyInjection\Extension\Extension {
            public function getAlias(): string
            {
                return 'mcp';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        McpCoreConfig::prependServerConfig($container, ['instructions' => 'x']);
        self::assertSame([['servers' => ['default' => ['instructions' => 'x']]]], $container->getExtensionConfig('mcp'));

        $container->prependExtensionConfig('emagma_mcp_core', ['server' => 'shop']);
        $this->prepend($container);
        self::assertSame(['servers' => ['shop' => ['pagination_limit' => 250]]], $container->getExtensionConfig('mcp')[0]);
    }

    public function testItsLogChannelsAreDeclaredWhenTheHostHasMonolog(): void
    {
        $container = $this->kernelContainer();
        $this->prepend($container);
        self::assertSame([], $container->getExtensionConfig('monolog'));

        $container->registerExtension(new class() extends \Symfony\Component\DependencyInjection\Extension\Extension {
            public function getAlias(): string
            {
                return 'monolog';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        $this->prepend($container);

        self::assertSame([['channels' => ['emagma_mcp_core', 'emagma_mcp_core_authorization']]], $container->getExtensionConfig('monolog'));
    }
}
