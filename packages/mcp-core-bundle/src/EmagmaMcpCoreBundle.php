<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\HandshakeEraPass;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\InstructionsPass;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\ToolCallLocatorPass;
use Emagma\McpCoreBundle\DependencyInjection\Compiler\ToolClassMapPass;
use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Emagma\McpCoreBundle\Instruction\AsMcpInstruction;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Attaches to one server of symfony/mcp-bundle: register it after McpBundle, whose server its compiler passes reach.
 */
final class EmagmaMcpCoreBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('server')
                    ->info(
                        'The server of `mcp.servers` (symfony/mcp-bundle) this bundle attaches to: its registry is'
                        . ' the one the authorization decorates, its builder the one the tools/call chain plugs into.'
                        . ' One server per application.',
                    )
                    ->cannotBeEmpty()
                    ->defaultValue('default')
                ->end()
                ->arrayNode('permission_domains')
                    ->info(
                        'Namespace prefix => permission domain. A grant is `mcp:<domain>:<resource>:<action>`: the'
                        . ' domain names the business solution a bundle covers (sylius, sylius_cms, core), never the'
                        . ' server that hosts it. Each bundle that brings tools prepends its own entry; the tools of'
                        . ' a namespace listed nowhere are authorizable by no grant.',
                    )
                    ->useAttributeAsKey('namespace')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->scalarNode('untrusted_content_notice')
                    ->info(
                        'What the model is told, once per tool result, about the fields the outputs mark as written'
                        . ' by third parties. Null keeps the bundle\'s own wording; a host that knows who writes into'
                        . ' its backend can say it in its own terms.',
                    )
                    ->defaultNull()
                ->end()
                ->arrayNode('write_confirmation')
                    ->addDefaultsIfNotSet()
                    ->info('Whether a model-supplied `confirm` may stand in for human consent.')
                    ->children()
                        ->arrayNode('require_human')
                            ->info(
                                'Actions for which a model-supplied `confirm: true` is NEVER accepted over an'
                                . ' MCP session. The model reads text written by people outside the organisation'
                                . ' (product reviews, order notes), so its `confirm` can be produced by an'
                                . ' injected instruction. Empty this list only on a deployment that has no'
                                . ' human in the loop by design, and understand that it re-opens that path.',
                            )
                            ->scalarPrototype()->end()
                            ->defaultValue([Action::Write->value, Action::Delete->value])
                            ->validate()
                                ->always(static function (array $actions): array {
                                    foreach ($actions as $action) {
                                        if (!\is_string($action) || null === Action::tryFrom($action)) {
                                            throw new \InvalidArgumentException(sprintf(
                                                'Unknown MCP action "%s". Expected one of: %s.',
                                                \is_string($action) ? $action : get_debug_type($action),
                                                implode(', ', array_column(Action::cases(), 'value')),
                                            ));
                                        }
                                    }

                                    return array_values(array_unique($actions));
                                })
                            ->end()
                        ->end()
                        ->floatNode('auto_cancel_threshold')
                            ->info(
                                'Seconds under which an elicitation answered `cancel` is taken as the client\'s own'
                                . ' cancel, not a human\'s: the session is then handled as one without elicitation'
                                . ' (trusted_clients and require_human decide). Some clients declare elicitation and'
                                . ' cancel it at once where they cannot show it (Claude Code in a claude.ai cloud'
                                . ' session). 0, the default, disables the detection: every cancel is a refusal.',
                            )
                            ->defaultValue(0.0)
                            ->min(0.0)
                        ->end()
                        ->arrayNode('trusted_clients')
                            ->info(
                                'MCP client names (`clientInfo.name` sent at initialize) whose sessions may'
                                . ' confirm a `require_human` action on the model\'s word. The name is set by'
                                . ' the client software, not by the model, so it cannot be prompt-injected.'
                                . ' List only clients whose own UI makes a human approve every tool call'
                                . ' (e.g. "Anthropic/ClaudeAI": Claude Web asks the user before each call'
                                . ' but does not implement elicitation). Empty by default.',
                            )
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->validate()
                                ->always(static function (array $clients): array {
                                    foreach ($clients as $client) {
                                        if (!\is_string($client) || '' === trim($client)) {
                                            throw new \InvalidArgumentException(sprintf(
                                                'A trusted MCP client must be a non-empty name, got %s.',
                                                \is_string($client) ? '""' : get_debug_type($client),
                                            ));
                                        }
                                    }

                                    return array_values(array_unique($clients));
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->info(
                        'Extends what is redacted from the audit and the traces. The components name what'
                        . ' their backend carries; a host with custom fields has to be able to name them'
                        . ' without forking the redactor.',
                    )
                    ->children()
                        ->scalarNode('marker')
                            ->info(
                                'What replaces a redacted value. Change it only on an empty audit: an entry'
                                . ' redacted under another marker is no longer recognised as such.',
                            )
                            ->cannotBeEmpty()
                            ->defaultValue('__REDACTED__')
                        ->end()
                        ->arrayNode('personal_keys')
                            ->info('Extra payload keys (case-insensitive, exact match) holding personal data.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('secret_fragments')
                            ->info('Extra substrings that mark a key as holding a secret, e.g. "token_hmac".')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('privacy')
                    ->addDefaultsIfNotSet()
                    ->info(
                        'How much of a person a tool result shows the model, per OAuth client of the caller'
                        . ' (the `azp` claim of the access token). `pseudonymise` replaces identifiers (email,'
                        . ' phone, login) by stable tokens and leaves surplus personal data (birthday, gender)'
                        . ' out; `off` serves everything in clear, for a model the merchant hosts. A client not'
                        . ' listed gets `mode`; `reveal: false` on a client refuses `reveal: true` even to a'
                        . ' caller holding the core:pii:reveal grant.',
                    )
                    ->children()
                        ->enumNode('mode')
                            ->values(array_column(PrivacyMode::cases(), 'value'))
                            ->defaultValue(PrivacyMode::Pseudonymise->value)
                        ->end()
                        ->scalarNode('secret')
                            ->info(
                                'Keys the pseudonymising tokens. Defaults to the kernel secret; set MCP_PRIVACY_SECRET'
                                . ' to rotate the tokens independently of the application secret.',
                            )
                            ->defaultValue('%env(default:kernel.secret:MCP_PRIVACY_SECRET)%')
                        ->end()
                        ->arrayNode('clients')
                            ->useAttributeAsKey('client_id')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->beforeNormalization()
                                    ->ifString()
                                    ->then(static fn (string $mode): array => ['mode' => $mode])
                                ->end()
                                ->children()
                                    ->enumNode('mode')
                                        ->values(array_column(PrivacyMode::cases(), 'value'))
                                        ->isRequired()
                                    ->end()
                                    ->booleanNode('reveal')->defaultTrue()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('monolog')) {
            $builder->prependExtensionConfig('monolog', ['channels' => ['emagma_mcp_core', 'emagma_mcp_core_authorization']]);
        }

        // One page: the grants filter each page after the SDK cut it.
        McpCoreConfig::prependServerConfig($builder, ['pagination_limit' => 250]);
    }

    /**
     * @param array{server: string, permission_domains: array<string, string>, untrusted_content_notice: string|null, write_confirmation: array{require_human: list<string>, trusted_clients: list<string>, auto_cancel_threshold: float}, redaction: array{marker: string, personal_keys: list<string>, secret_fragments: list<string>}, privacy: array{mode: string, secret: string, clients: array<string, array{mode: string, reveal: bool}>}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerAttributeForAutoconfiguration(
            AsMcpInstruction::class,
            static function (ChildDefinition $definition, AsMcpInstruction $attribute): void {
                $definition->addTag(InstructionsPass::TAG, ['priority' => $attribute->priority]);
            },
        );

        $this->attachToServer($builder, $config['server']);

        $builder->getDefinition('emagma_mcp_core.permission_resolver')->setArgument(0, $config['permission_domains']);

        $builder
            ->getDefinition('emagma_mcp_core.listener.write_confirmation_policy')
            ->replaceArgument(0, $config['write_confirmation']['require_human'])
            ->replaceArgument(1, $config['write_confirmation']['trusted_clients'])
            ->replaceArgument(2, $config['write_confirmation']['auto_cancel_threshold'])
        ;

        $builder
            ->getDefinition('emagma_mcp_core.listener.privacy_context')
            ->replaceArgument(1, $config['privacy']['mode'])
            ->replaceArgument(2, $config['privacy']['clients'])
            ->replaceArgument(3, $config['privacy']['secret'])
        ;

        $builder
            ->getDefinition('emagma_mcp_core.redactor')
            ->replaceArgument(0, $config['redaction']['personal_keys'])
            ->replaceArgument(1, $config['redaction']['secret_fragments'])
            ->replaceArgument(3, $config['redaction']['marker'])
        ;

        // Null keeps the listener's own notice.
        if (null !== $config['untrusted_content_notice']) {
            $builder->getDefinition('emagma_mcp_core.listener.untrusted_content_notice')->setArgument(0, $config['untrusted_content_notice']);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HandshakeEraPass());
        // Before the tagged iterator of McpInstructions is resolved: the literal becomes a tagged service.
        $container->addCompilerPass(new InstructionsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, priority: 10);
        // After McpPass: both read what it put on the server's builder.
        $container->addCompilerPass(new ToolCallLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, priority: -10);
        $container->addCompilerPass(new ToolClassMapPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, priority: -10);
    }

    private function attachToServer(ContainerBuilder $container, string $server): void
    {
        $container->setParameter('emagma_mcp_core.server', $server);
        $container->setAlias('emagma_mcp_core.registry', \sprintf('mcp.server.%s.registry', $server));
        $container->setAlias('emagma_mcp_core.server', \sprintf('mcp.server.%s', $server));

        // Decoration takes the real id: an alias cannot be decorated.
        $container->getDefinition('emagma_mcp_core.authorization_registry')
            ->setDecoratedService(\sprintf('mcp.server.%s.registry', $server));
    }
}
