<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\DependencyInjection;

use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Emagma\McpSyliusBundle\AdminApi\SyliusSubjectReferences;
use Emagma\McpSyliusBundle\DependencyInjection\Compiler\ShopExtensionsPass;
use Emagma\McpSyliusBundle\EmagmaMcpSyliusBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(EmagmaMcpSyliusBundle::class)]
#[CoversClass(ShopExtensionsPass::class)]
final class EmagmaMcpSyliusBundleTest extends TestCase
{
    /** The model writes `file.download_url`: by default the server fetches it from ChatGPT's file store only. */
    public function testAttachedFilesComeFromTheClientFileStoreUnlessToldOtherwise(): void
    {
        self::assertSame(['oaiusercontent.com', 'oaisdmntpr*.blob.core.windows.net'], $this->load([])->getParameter('emagma_mcp_sylius.file_params.allowed_hosts'));
        self::assertSame(['*'], $this->load(['file_params' => ['allowed_hosts' => ['*']]])->getParameter('emagma_mcp_sylius.file_params.allowed_hosts'));
    }

    /** An `%env()%` in a parameter, never a value compiled into the container. */
    public function testTheShopIsAnEnvironmentVariableByDefaultAndAnOptionOtherwise(): void
    {
        $container = $this->load([]);
        self::assertSame('%env(SYLIUS_API_URL)%', $container->getParameter('emagma_mcp_sylius.api_url'));
        self::assertSame('%env(MCP_SYLIUS_VERSION)%', $container->getParameter('emagma_mcp_sylius.sylius_version'));
        self::assertSame('%env(MCP_SHOP_EXTENSIONS)%', $container->getParameter('emagma_mcp_sylius.shop_extensions'));
        self::assertSame('%env(MCP_DEFAULT_LOCALE)%', $container->getParameter('emagma_mcp_sylius.default_locale'));
        self::assertSame('%env(MCP_SERVICE_ACCOUNT_EMAIL)%', $container->getParameter('emagma_mcp_sylius.service_account.email'));

        $container = $this->load(['api_url' => '%env(MY_SHOP_API)%', 'sylius_version' => '2.2', 'service_account' => ['email' => 'bot@example.com']]);
        self::assertSame('%env(MY_SHOP_API)%', $container->getParameter('emagma_mcp_sylius.api_url'));
        self::assertSame('2.2', $container->getParameter('emagma_mcp_sylius.sylius_version'));
        self::assertSame('bot@example.com', $container->getParameter('emagma_mcp_sylius.service_account.email'));
        self::assertSame('%env(MCP_SERVICE_ACCOUNT_PASSWORD)%', $container->getParameter('emagma_mcp_sylius.service_account.password'));
    }

    /** An empty scan of the `#[Personal]` marks would leave customers' data in the audit. */
    public function testTheRedactionIsGivenThePersonalKeysOfTheOutputs(): void
    {
        $keys = $this->load([])->getDefinition('emagma_mcp_sylius.sensitive_keys')->getArgument(0);

        self::assertIsArray($keys);
        self::assertGreaterThanOrEqual(10, \count($keys));
        foreach (['email', 'firstname', 'lastname', 'phonenumber', 'street'] as $key) {
            self::assertContains($key, $keys);
        }
        self::assertTrue($this->load([])->getDefinition('emagma_mcp_sylius.sensitive_keys')->hasTag('emagma_mcp_core.sensitive_keys'));
    }

    public function testAuditSnapshotsArePutBackThroughTheAdminApi(): void
    {
        self::assertSame('emagma_mcp_sylius.revert_target', (string) $this->load([])->getAlias(RevertTargetInterface::class));
    }

    public function testAnErasureFollowsTheIrisOfACustomer(): void
    {
        self::assertSame('emagma_mcp_sylius.subject_references', (string) $this->load([])->getAlias(SubjectReferencesInterface::class));
        self::assertSame(['/customers/12'], (new SyliusSubjectReferences())->suffixes('customer', '12'));
        self::assertSame([], (new SyliusSubjectReferences())->suffixes('product', 'MUG'));
    }

    /** The extension is the reason a call gets when both gates refuse. */
    public function testTheShopExtensionGateIsAskedBeforeTheVersionGate(): void
    {
        $container = $this->load([]);

        self::assertSame([['priority' => 10]], $container->getDefinition('emagma_mcp_sylius.gate.shop_extension')->getTag('emagma_mcp_core.tool_gate'));
        self::assertSame([[]], $container->getDefinition('emagma_mcp_sylius.gate.sylius_version')->getTag('emagma_mcp_core.tool_gate'));
    }

    public function testTheConventionsOfTheToolsComeFirstInTheServersInstructions(): void
    {
        $definition = $this->load([])->getDefinition('emagma_mcp_sylius.instruction');

        self::assertSame([['priority' => 100]], $definition->getTag('emagma_mcp_core.instruction'));
    }

    public function testItsOwnServicesAreNamedAfterTheAliasAndWiredExplicitly(): void
    {
        foreach ($this->load([])->getDefinitions() as $id => $definition) {
            if ('service_container' === $id || str_starts_with($id, 'Emagma\\McpSyliusBundle\\Tool\\')) {
                continue;
            }
            self::assertStringStartsWith('emagma_mcp_sylius.', $id);
            self::assertFalse($definition->isAutowired(), $id . ' is wired explicitly');
        }
    }

    public function testTheExtensionsThatExistAreThoseTheInstalledBundlesDeclare(): void
    {
        $container = $this->load([]);
        $container->setDefinition('an_extension', (new Definition())->addTag('emagma_mcp_sylius.shop_extension', ['extension' => 'sylius_cms']));
        $container->setDefinition('another', (new Definition())->addTag('emagma_mcp_sylius.shop_extension', ['extension' => 'wishlist'])->addTag('emagma_mcp_sylius.shop_extension', ['extension' => 'sylius_cms']));

        (new ShopExtensionsPass())->process($container);

        self::assertSame(['sylius_cms', 'wishlist'], $container->getDefinition('emagma_mcp_sylius.shop_extensions')->getArgument(1));
    }

    public function testWithoutAnExtensionBundleNoneExists(): void
    {
        $container = $this->load([]);

        (new ShopExtensionsPass())->process($container);

        self::assertSame([], $container->getDefinition('emagma_mcp_sylius.shop_extensions')->getArgument(1));
    }

    public function testAnExtensionTagMustNameTheExtension(): void
    {
        $container = $this->load([]);
        $container->setDefinition('an_extension', (new Definition())->addTag('emagma_mcp_sylius.shop_extension'));

        $this->expectException(InvalidArgumentException::class);

        (new ShopExtensionsPass())->process($container);
    }

    public function testTheTwoHttpClientsAreDefaultsOfTheHostsFramework(): void
    {
        $container = $this->builder();
        $container->registerExtension($this->extensionNamed('framework'));
        $container->registerExtension($this->extensionNamed('emagma_mcp_core'));
        $container->prependExtensionConfig('emagma_mcp_sylius', ['api_url' => 'https://shop.example.com/api/v2/admin/']);

        $this->extension()->prepend($container);

        $clients = $container->getExtensionConfig('framework')[0]['http_client']['scoped_clients'];
        self::assertSame('https://shop.example.com/api/v2/admin/', $clients['emagma_mcp_sylius.admin_api.http_client']['base_uri']);
        self::assertSame(['Accept' => 'application/ld+json', 'Content-Type' => 'application/ld+json'], $clients['emagma_mcp_sylius.admin_api.http_client']['headers']);
        self::assertSame(30, $clients['emagma_mcp_sylius.admin_api.http_client']['max_duration']);
        self::assertSame('^https://', $clients['emagma_mcp_sylius.file_download.http_client']['scope']);
        self::assertSame(0, $clients['emagma_mcp_sylius.file_download.http_client']['max_redirects'], 'a redirect would leave the allowed host');

        $notice = $container->getExtensionConfig('emagma_mcp_core')[0]['untrusted_content_notice'];
        self::assertStringContainsString('customers and visitors), not by the shop', $notice);
    }

    public function testTheShopOfTheHttpClientIsTheLastOneConfigured(): void
    {
        $container = $this->builder();
        $container->registerExtension($this->extensionNamed('framework'));
        $container->registerExtension($this->extensionNamed('emagma_mcp_core'));
        $container->registerExtension($this->extension());
        $container->loadFromExtension('emagma_mcp_sylius', ['api_url' => 'https://first.example.com/']);
        $container->loadFromExtension('emagma_mcp_sylius', ['bench' => true]);
        $container->loadFromExtension('emagma_mcp_sylius', ['api_url' => 'https://last.example.com/']);

        $this->extension()->prepend($container);

        self::assertSame('https://last.example.com/', $container->getExtensionConfig('framework')[0]['http_client']['scoped_clients']['emagma_mcp_sylius.admin_api.http_client']['base_uri']);
    }

    public function testTheShopOfTheHttpClientIsTheEnvironmentVariableByDefault(): void
    {
        $container = $this->builder();
        $container->registerExtension($this->extensionNamed('framework'));
        $container->registerExtension($this->extensionNamed('emagma_mcp_core'));

        $this->extension()->prepend($container);

        self::assertSame('%env(SYLIUS_API_URL)%', $container->getExtensionConfig('framework')[0]['http_client']['scoped_clients']['emagma_mcp_sylius.admin_api.http_client']['base_uri']);
    }

    public function testTheServerGetsTheToolsAndNoLiteralInstructions(): void
    {
        $container = $this->builder();
        $container->registerExtension($this->extensionNamed('mcp'));
        $container->registerExtension($this->extensionNamed('emagma_mcp_core'));
        $container->prependExtensionConfig('emagma_mcp_core', ['server' => 'shop']);
        $container->prependExtensionConfig('emagma_mcp_sylius', ['default_locale' => 'fr_FR']);

        $this->extension()->prepend($container);

        $server = $container->getExtensionConfig('mcp')[0]['servers']['shop'];
        self::assertSame(['Emagma\\McpSyliusBundle\\Tool\\'], $server['registry']['tools']);
        self::assertArrayNotHasKey('instructions', $server, 'an instruction service, assembled by the core');
    }

    public function testWithoutTheFrameworkOrTheCoreNothingIsPrepended(): void
    {
        $container = $this->builder();

        $this->extension()->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
        self::assertSame([], $container->getExtensionConfig('emagma_mcp_core'));
        self::assertSame([], $container->getExtensionConfig('mcp'));
    }

    public function testTheBenchIsRegisteredOnlyWhenAsked(): void
    {
        self::assertFalse($this->load([])->hasDefinition('emagma_mcp_sylius.command.bench_agent'));

        $container = $this->load(['bench' => true]);
        self::assertTrue($container->getDefinition('emagma_mcp_sylius.command.bench_agent')->hasTag('console.command'));
        self::assertSame(['emagma_mcp_sylius.admin_api.http_client', null, 0], $container->getDefinition('emagma_mcp_sylius.admin_api.http_client.traceable')->getDecoratedService());
    }

    private function builder(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.project_dir' => sys_get_temp_dir()]));
    }

    private function extension(): ExtensionInterface&PrependExtensionInterface
    {
        $extension = (new EmagmaMcpSyliusBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        return $extension;
    }

    private function extensionNamed(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(
                private readonly string $name,
            ) {
            }

            public function getAlias(): string
            {
                return $this->name;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = $this->builder();
        $this->extension()->load([$config], $container);

        return $container;
    }
}
