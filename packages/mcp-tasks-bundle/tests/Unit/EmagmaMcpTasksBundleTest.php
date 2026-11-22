<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit;

use Emagma\McpTasksBundle\DependencyInjection\Compiler\LinterPass;
use Emagma\McpTasksBundle\EmagmaMcpTasksBundle;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;
use Emagma\McpTasksBundle\Script\ScriptLinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(EmagmaMcpTasksBundle::class)]
#[CoversClass(LinterPass::class)]
final class EmagmaMcpTasksBundleTest extends TestCase
{
    private const array DELEGATION = ['delegation' => ['token_handler' => 'some.token_handler']];

    public function testItsAliasIsDerivedFromItsVendorPrefixedName(): void
    {
        self::assertSame('emagma_mcp_tasks', $this->extension()->getAlias());
    }

    public function testWithoutAnOrchestratorTheContainerIsNotBuilt(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no orchestrator: register emagma/mcp-tasks-messenger-bundle or set emagma_mcp_tasks.orchestrator');

        $this->extension()->load([self::DELEGATION], $this->container());
    }

    public function testTheOrchestratorIsTheServiceItNames(): void
    {
        $container = $this->load([]);

        self::assertSame('app.orchestrator', (string) $container->getAlias('emagma_mcp_tasks.orchestrator'));
        self::assertSame('emagma_mcp_tasks.orchestrator', (string) $container->getAlias(TaskOrchestratorInterface::class));
    }

    public function testTheTokenHandlerAndThePlatformAreTheServicesItNames(): void
    {
        $container = $this->load(['platform' => 'ai.platform.mine']);

        self::assertSame('some.token_handler', (string) $container->getAlias('emagma_mcp_tasks.token_handler'));
        self::assertSame('ai.platform.mine', (string) $container->getAlias('emagma_mcp_tasks.platform'));
        self::assertSame('ai.platform.anthropic', (string) $this->load([])->getAlias('emagma_mcp_tasks.platform'));
    }

    public function testTheDelegationNeedsTheHandlerOfTheFirewall(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->extension()->load([['orchestrator' => 'app.orchestrator']], $this->container());
    }

    public function testTheLimitsAndTheDirectoryReachTheServicesAsParameters(): void
    {
        $container = $this->load(['limits' => ['step_timeout' => 60, 'max_calls' => 5]]);

        self::assertSame(60.0, $container->getParameter('emagma_mcp_tasks.limits.step_timeout'));
        self::assertSame(5, $container->getParameter('emagma_mcp_tasks.limits.max_calls'));
        self::assertSame('128M', $container->getParameter('emagma_mcp_tasks.limits.memory_limit'));
        self::assertSame('%kernel.project_dir%/var/tasks', $container->getParameter('emagma_mcp_tasks.directory'));
    }

    public function testTheEnvironmentOfTheDelegationIsEmptyUntilTheHostSetsIt(): void
    {
        self::assertSame('', $this->load([])->getParameter('env(MCP_TASKS_CLIENT_ID)'));
    }

    public function testTheLinterIsOffByDefault(): void
    {
        self::assertFalse($this->load([])->hasDefinition('emagma_mcp_tasks.script_linter'));
        // Enabled without a command: ECS with the bundle's rules, on the directory of the task.
        self::assertSame(ScriptLinter::PLACEHOLDER, ScriptLinter::defaultCommand()[array_key_last(ScriptLinter::defaultCommand())]);
        self::assertContains('--config=' . ScriptLinter::config(), ScriptLinter::defaultCommand());
    }

    public function testAnEnabledLinterIsRegisteredWithItsDefaultCommand(): void
    {
        $linter = $this->load(['linter' => ['enabled' => true, 'path' => '/bin/true']])->getDefinition('emagma_mcp_tasks.script_linter');

        self::assertSame(['/bin/true', ScriptLinter::defaultCommand()], $linter->getArguments());
    }

    public function testAnEnabledLinterNeedsItsPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('emagma_mcp_tasks.linter.path is required');

        $this->load(['linter' => ['enabled' => true]]);
    }

    public function testItsCommandIsGivenTheDirectoryOfTheTaskAndNothingElse(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must hold {task}');

        $this->load(['linter' => ['enabled' => true, 'path' => '/bin/true', 'command' => ['fix', '/srv/app']]]);
    }

    public function testAnEnabledLinterThatIsNotInstalledStopsTheBuild(): void
    {
        $container = $this->load(['linter' => ['enabled' => true, 'path' => '/nowhere/bin/ecs']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('its path "/nowhere/bin/ecs" is not an executable file');

        (new LinterPass())->process($container);
    }

    public function testAnInstalledLinterPasses(): void
    {
        $container = $this->load(['linter' => ['enabled' => true, 'path' => '/bin/true']]);

        (new LinterPass())->process($container);

        self::assertTrue($container->hasDefinition('emagma_mcp_tasks.script_linter'));
    }

    public function testItsServicesAreNamedAfterTheAliasAndReachedByTheirContracts(): void
    {
        $container = $this->load([]);

        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with((string) $definition->getClass(), 'Emagma\\McpTasksBundle\\') && !str_starts_with((string) $definition->getClass(), 'Emagma\\McpTasksBundle\\Tool\\')) {
                self::assertStringStartsWith('emagma_mcp_tasks.', $id, 'only the tools, loaded by namespace, keep their class as id');
                self::assertFalse($definition->isAutowired(), $id . ' is wired explicitly');
            }
        }
        self::assertSame('emagma_mcp_tasks.step_executor', (string) $container->getAlias(StepExecutor::class));
    }

    public function testTheEntitiesAreMappedByAttributesForTheHost(): void
    {
        $container = $this->prepend('doctrine');

        $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['EmagmaMcpTasksBundle'] ?? null;
        self::assertIsArray($mapping);
        self::assertSame('attribute', $mapping['type']);
        self::assertSame('Emagma\\McpTasksBundle\\Entity', $mapping['prefix']);
        self::assertFileExists($mapping['dir'] . '/Task.php');
    }

    public function testItsMigrationsAreAddedToTheHostsWhenTheMigrationsBundleIsThere(): void
    {
        self::assertSame([], $this->prepend()->getExtensionConfig('doctrine_migrations'));

        $paths = $this->prepend('doctrine_migrations')->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'] ?? null;
        self::assertIsArray($paths);
        self::assertDirectoryExists($paths['Emagma\\McpTasksBundle\\Migrations']);
    }

    private function extension(): ExtensionInterface
    {
        $extension = (new EmagmaMcpTasksBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        return $extension;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = $this->container();
        $this->extension()->load([self::DELEGATION, ['orchestrator' => 'app.orchestrator'], $config], $container);

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
