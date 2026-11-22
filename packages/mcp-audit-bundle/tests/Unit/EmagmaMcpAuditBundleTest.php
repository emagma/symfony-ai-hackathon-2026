<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Emagma\McpAuditBundle\DependencyInjection\Compiler\DefaultRevertTargetPass;
use Emagma\McpAuditBundle\EmagmaMcpAuditBundle;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\EventListener\AuditTableListener;
use Emagma\McpAuditBundle\Forget\NoSubjectReferences;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpAuditBundle\Revert\NullRevertTarget;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpCoreBundle\Journal\SubjectReferencesInterface;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(EmagmaMcpAuditBundle::class)]
#[CoversClass(DefaultRevertTargetPass::class)]
#[CoversClass(AuditTableListener::class)]
#[CoversClass(NoSubjectReferences::class)]
final class EmagmaMcpAuditBundleTest extends TestCase
{
    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_audit', $this->extension()->getAlias());
    }

    public function testAuditEntriesLiveAYearUnlessToldOtherwise(): void
    {
        self::assertSame('P1Y', $this->load([])->getParameter('emagma_mcp_audit.retention'));
        self::assertSame('P90D', $this->load(['retention' => 'P90D'])->getParameter('emagma_mcp_audit.retention'));
    }

    public function testARetentionThatIsNotADurationIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ISO 8601');

        $this->load(['retention' => '1 year']);
    }

    public function testThePurgeCommandGetsTheConfiguredRetention(): void
    {
        self::assertSame('%emagma_mcp_audit.retention%', $this->load([])->getDefinition('emagma_mcp_audit.command.purge')->getArgument(1));
    }

    public function testTheTableIsNamedByTheHost(): void
    {
        self::assertSame('mcp_audit', $this->load([])->getParameter('emagma_mcp_audit.table'));
        self::assertSame('my_trail', $this->load(['table' => 'my_trail'])->getParameter('emagma_mcp_audit.table'));
        self::assertSame('%emagma_mcp_audit.table%', $this->load([])->getDefinition('emagma_mcp_audit.listener.audit_table')->getArgument(0));

        $metadata = new ClassMetadata(AuditEntry::class);
        $metadata->setPrimaryTable(['name' => 'mcp_audit', 'indexes' => ['idx_batch' => ['columns' => ['batch_id']]]]);
        (new AuditTableListener('my_trail'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('my_trail', $metadata->getTableName());
        self::assertArrayHasKey('idx_batch', $metadata->table['indexes'] ?? [], 'the indexes of the mapping are kept');
    }

    public function testItsServicesAreNamedAfterTheAliasAndReachedByTheirContracts(): void
    {
        $container = $this->load([]);

        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with((string) $definition->getClass(), 'Emagma\\McpAuditBundle\\') && !str_starts_with((string) $definition->getClass(), 'Emagma\\McpAuditBundle\\Tool\\')) {
                self::assertStringStartsWith('emagma_mcp_audit.', $id, 'only the tools, loaded by namespace, keep their class as id');
                self::assertFalse($definition->isPublic(), $id . ' is private');
                self::assertFalse($definition->isAutowired(), $id . ' is wired explicitly');
            }
        }
        self::assertSame('emagma_mcp_audit.writer', (string) $container->getAlias(WriteJournalInterface::class));
        self::assertSame('emagma_mcp_audit.repository', (string) $container->getAlias(AuditRepositoryInterface::class));
    }

    public function testTheAuditWriterCanReportAFailedFlushInsteadOfThrowing(): void
    {
        $definition = $this->load([])->getDefinition('emagma_mcp_audit.writer');

        self::assertEquals(new Reference(ActorProviderInterface::class), $definition->getArgument(2));
        self::assertSame('logger', (string) $definition->getArgument(3));
        self::assertEquals(new Reference(ToolClassMap::class), $definition->getArgument(4), 'the tools by name, to record whether an entry can be reverted');
        self::assertSame([['channel' => 'emagma_mcp_audit']], $definition->getTag('monolog.logger'));
    }

    public function testAForgottenEntryIsLockedOnEveryUpdate(): void
    {
        $tags = $this->load([])->getDefinition('emagma_mcp_audit.listener.audit_entry_lock')->getTag('doctrine.event_listener');

        self::assertSame(['preUpdate'], array_column($tags, 'event'));
    }

    public function testWithoutABackendARevertHasNowhereToWrite(): void
    {
        $container = $this->load([]);

        (new DefaultRevertTargetPass())->process($container);

        self::assertSame('emagma_mcp_audit.revert_target.null', (string) $container->getAlias(RevertTargetInterface::class));
        self::assertSame(NullRevertTarget::class, $container->getDefinition('emagma_mcp_audit.revert_target.null')->getClass());
    }

    public function testWithoutABackendAnErasureReachesTheSubjectsOwnEntriesOnly(): void
    {
        $container = $this->load([]);

        (new DefaultRevertTargetPass())->process($container);

        self::assertSame('emagma_mcp_audit.subject_references.none', (string) $container->getAlias(SubjectReferencesInterface::class));
        self::assertSame([], (new NoSubjectReferences())->suffixes('customer', '12'));
    }

    public function testTheBackendsRevertTargetIsLeftInPlace(): void
    {
        $container = $this->load([]);
        $container->setAlias(RevertTargetInterface::class, 'a_backend.revert_target');

        (new DefaultRevertTargetPass())->process($container);

        self::assertSame('a_backend.revert_target', (string) $container->getAlias(RevertTargetInterface::class));
    }

    public function testTheEntityIsMappedByAttributesForTheHost(): void
    {
        $container = $this->prepend('doctrine');

        $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['EmagmaMcpAuditBundle'] ?? null;
        self::assertIsArray($mapping);
        self::assertSame('attribute', $mapping['type']);
        self::assertSame('Emagma\\McpAuditBundle\\Entity', $mapping['prefix']);
        self::assertFileExists($mapping['dir'] . '/AuditEntry.php');
    }

    public function testItsMigrationsAreAddedToTheHostsWhenTheMigrationsBundleIsThere(): void
    {
        self::assertSame([], $this->prepend()->getExtensionConfig('doctrine_migrations'));

        $paths = $this->prepend('doctrine_migrations')->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'] ?? null;
        self::assertIsArray($paths);
        self::assertDirectoryExists($paths['Emagma\\McpAuditBundle\\Migrations']);
    }

    public function testItsLogChannelIsDeclaredWhenTheHostHasMonolog(): void
    {
        self::assertSame([], $this->prepend()->getExtensionConfig('monolog'));
        self::assertSame([['channels' => ['emagma_mcp_audit']]], $this->prepend('monolog')->getExtensionConfig('monolog'));
    }

    private function extension(): ExtensionInterface
    {
        $extension = (new EmagmaMcpAuditBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        return $extension;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = $this->container();
        $this->extension()->load([$config], $container);

        return $container;
    }

    private function prepend(string ...$hostExtensions): ContainerBuilder
    {
        $container = $this->container();
        foreach ($hostExtensions as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(
                    private readonly string $alias,
                ) {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }
            });
        }

        $extension = $this->extension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        return $container;
    }

    private function container(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
    }
}
