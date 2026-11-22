<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Emagma\McpTasksBundle\DependencyInjection\Compiler\LinterPass;
use Emagma\McpTasksBundle\Script\ScriptLinter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings its own wiring as prepended defaults: the tools on the core's server, their permission domain,
 * the Doctrine mapping and the migrations. The orchestrator is the host's choice, with no default.
 */
final class EmagmaMcpTasksBundle extends AbstractBundle
{
    public const string NO_ORCHESTRATOR = 'no orchestrator: register emagma/mcp-tasks-messenger-bundle or set emagma_mcp_tasks.orchestrator';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('orchestrator')
                    ->info(
                        'The id of the TaskOrchestratorInterface service that plays the steps. No default: an orchestrator'
                        . ' bundle (emagma/mcp-tasks-messenger-bundle) sets its own, or the host names one.',
                    )
                    ->defaultNull()
                ->end()
                ->scalarNode('directory')
                    ->info('Where the scripts of the tasks are stored, one directory per task. Shared by the server and its worker.')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/var/tasks')
                ->end()
                ->scalarNode('platform')
                    ->info('The Symfony AI platform service that reads the scripts and serves llm_transform (`ai.platform.<name>`).')
                    ->cannotBeEmpty()
                    ->defaultValue('ai.platform.anthropic')
                ->end()
                ->scalarNode('model')
                    ->cannotBeEmpty()
                    ->defaultValue('claude-haiku-4-5')
                ->end()
                ->integerNode('max_output_tokens')
                    ->info('The output budget of one reading and of one llm_transform.')
                    ->min(256)
                    ->defaultValue(4000)
                ->end()
                ->scalarNode('sender')
                    ->info('The From of the emails that tell an owner their task is over or stopped.')
                    ->cannotBeEmpty()
                    ->defaultValue('mcp-tasks@localhost')
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('step_timeout')->info('Seconds one step may last.')->min(1)->defaultValue(14400)->end()
                        ->integerNode('max_calls')->info('Tool calls one task may make.')->min(1)->defaultValue(100000)->end()
                        ->scalarNode('memory_limit')->info('memory_limit of the process of a step.')->cannotBeEmpty()->defaultValue('128M')->end()
                        ->integerNode('max_input_length')->info('Characters of one content llm_transform accepts.')->min(1)->defaultValue(20000)->end()
                    ->end()
                ->end()
                ->arrayNode('linter')
                    ->info(
                        'A linter run on the files of a task, in place, before they are inspected and read. Disabled: a'
                        . ' script that does not write the functions and classes of PHP with a leading backslash is'
                        . ' refused (not_fully_qualified). Enabled: its path is checked when the container is built.',
                    )
                    ->addDefaultsIfNotSet()
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('path')->info('The linter\'s binary, e.g. %kernel.project_dir%/vendor/bin/ecs.')->defaultNull()->end()
                        ->arrayNode('command')
                            ->info('Its arguments; {task} is the directory of the task, the only path it is given. Null: ECS with the bundle\'s rules.')
                            ->scalarPrototype()->end()
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $linter): bool => $linter['enabled'] && (null === $linter['path'] || '' === $linter['path']))
                        ->thenInvalid('emagma_mcp_tasks.linter.path is required when the linter is enabled.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $linter): bool => $linter['enabled'] && \is_array($linter['command']) && !\in_array(ScriptLinter::PLACEHOLDER, $linter['command'], true))
                        ->thenInvalid('emagma_mcp_tasks.linter.command must hold {task}, the directory of the task.')
                    ->end()
                ->end()
                ->arrayNode('delegation')
                    ->info('How the worker gets the rights of the user who launched a task: an OAuth client of the identity provider, with offline access.')
                    ->isRequired()
                    ->children()
                        ->scalarNode('authorize_url')->info('The authorization endpoint, as the browser of the user reaches it.')->defaultValue('%env(MCP_TASKS_AUTHORIZE_URL)%')->end()
                        ->scalarNode('token_url')->info('The token endpoint, as the server reaches it.')->defaultValue('%env(MCP_TASKS_TOKEN_URL)%')->end()
                        ->scalarNode('client_id')->defaultValue('%env(MCP_TASKS_CLIENT_ID)%')->end()
                        ->scalarNode('client_secret')->defaultValue('%env(MCP_TASKS_CLIENT_SECRET)%')->end()
                        ->scalarNode('redirect_uri')->info('The public URL of /tasks/authorize/callback on this server.')->defaultValue('%env(MCP_TASKS_REDIRECT_URI)%')->end()
                        ->scalarNode('token_handler')
                            ->info('The access-token handler of the firewall that guards the MCP endpoint: the worker\'s tokens go through the same check.')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('secret')->info('Encrypts the stored tokens and signs the links.')->defaultValue('%kernel.secret%')->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\McpTasksBundle\\', 'core');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => ['Emagma\\McpTasksBundle\\Tool\\']]]);

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EmagmaMcpTasksBundle' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $this->getPath() . '/src/Entity',
                            'prefix' => 'Emagma\\McpTasksBundle\\Entity',
                            'alias' => 'EmagmaMcpTasks',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Emagma\\McpTasksBundle\\Migrations' => $this->getPath() . '/migrations'],
            ]);
        }
    }

    /**
     * @param array{
     *     orchestrator: string|null,
     *     directory: string, platform: string, model: string, max_output_tokens: int, sender: string,
     *     limits: array{step_timeout: int, max_calls: int, memory_limit: string, max_input_length: int},
     *     linter: array{enabled: bool, path: string|null, command: list<string>|null},
     *     delegation: array{authorize_url: string, token_url: string, client_id: string, client_secret: string, redirect_uri: string, token_handler: string, secret: string},
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (null === $config['orchestrator'] || '' === $config['orchestrator']) {
            throw new InvalidConfigurationException(self::NO_ORCHESTRATOR);
        }

        foreach (['AUTHORIZE_URL', 'TOKEN_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'REDIRECT_URI'] as $name) {
            if (!$builder->hasParameter('env(MCP_TASKS_' . $name . ')')) {
                $builder->setParameter('env(MCP_TASKS_' . $name . ')', '');
            }
        }

        $delegation = $config['delegation'];
        $container->parameters()
            ->set('emagma_mcp_tasks.directory', $config['directory'])
            ->set('emagma_mcp_tasks.model', $config['model'])
            ->set('emagma_mcp_tasks.max_output_tokens', $config['max_output_tokens'])
            ->set('emagma_mcp_tasks.sender', $config['sender'])
            ->set('emagma_mcp_tasks.limits.step_timeout', (float) $config['limits']['step_timeout'])
            ->set('emagma_mcp_tasks.limits.max_calls', $config['limits']['max_calls'])
            ->set('emagma_mcp_tasks.limits.memory_limit', $config['limits']['memory_limit'])
            ->set('emagma_mcp_tasks.limits.max_input_length', $config['limits']['max_input_length'])
            ->set('emagma_mcp_tasks.delegation.authorize_url', $delegation['authorize_url'])
            ->set('emagma_mcp_tasks.delegation.token_url', $delegation['token_url'])
            ->set('emagma_mcp_tasks.delegation.client_id', $delegation['client_id'])
            ->set('emagma_mcp_tasks.delegation.client_secret', $delegation['client_secret'])
            ->set('emagma_mcp_tasks.delegation.redirect_uri', $delegation['redirect_uri'])
            ->set('emagma_mcp_tasks.delegation.secret', $delegation['secret'])
        ;

        $container->import('../config/services.php');

        // What the host chose, as aliases the explicit definitions read.
        $builder->setAlias('emagma_mcp_tasks.orchestrator', $config['orchestrator']);
        $builder->setAlias('emagma_mcp_tasks.platform', $config['platform']);
        $builder->setAlias('emagma_mcp_tasks.token_handler', $delegation['token_handler']);

        if ($config['linter']['enabled']) {
            $builder->register('emagma_mcp_tasks.script_linter', ScriptLinter::class)
                ->setArguments([$config['linter']['path'], $config['linter']['command'] ?? ScriptLinter::defaultCommand()]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new LinterPass());
    }
}
