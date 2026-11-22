<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle;

use Emagma\McpCoreBundle\DependencyInjection\McpCoreConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The tour, and what it shows that lives nowhere else: the fraud checker agent and the product translation.
 * The demos are the bundle's own files.
 */
final class EmagmaSymfonyAiHackathon2026Bundle extends AbstractBundle
{
    protected string $extensionAlias = self::ALIAS;

    public const string ALIAS = 'emagma_symfony_ai_hackathon_2026';

    /** A starting list: the shop extends it with what it meets. */
    public const array DISPOSABLE_DOMAINS = [
        '10minutemail.com', 'dispostable.com', 'guerrillamail.com', 'maildrop.cc', 'mailinator.com',
        'sharklasers.com', 'temp-mail.org', 'tempmail.com', 'throwawaymail.com', 'trashmail.com', 'yopmail.com',
    ];

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('fraud_checker_agent')
                    ->info('The background agent that looks at the customer accounts the shop creates.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Whether the agent runs on its own: on each new account, and on the schedule of the agents. `hackathon:agent:fraud` runs it by hand either way.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('every')
                            ->info('How often the agent also runs on the schedule of the agents: the safety net of the passes each new account asks for.')
                            ->cannotBeEmpty()
                            ->defaultValue('5 minutes')
                        ->end()
                        ->scalarNode('platform')
                            ->info('The Symfony AI platform service of the agent (`ai.platform.<name>`): the default of the background agents.')
                            ->cannotBeEmpty()
                            ->defaultValue('ai.platform.anthropic')
                        ->end()
                        ->scalarNode('model')
                            ->info('The model that settles the cases the rules cannot. It is only asked for those.')
                            ->cannotBeEmpty()
                            ->defaultValue('claude-haiku-4-5')
                        ->end()
                        ->integerNode('max_output_tokens')->min(128)->defaultValue(500)->end()
                        ->arrayNode('disposable_domains')
                            ->info('Domains of disposable address services: an account on one is reported as high without asking the model.')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DISPOSABLE_DOMAINS)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\SymfonyAiHackathon2026Bundle\\', 'core');
        // The longest prefix wins: product_translate keeps its grants of the sylius domain.
        McpCoreConfig::prependPermissionDomain($builder, 'Emagma\\SymfonyAiHackathon2026Bundle\\ProductTranslation\\', 'sylius');
        McpCoreConfig::prependServerConfig($builder, ['registry' => ['tools' => [
            'Emagma\\SymfonyAiHackathon2026Bundle\\Tool\\',
            'Emagma\\SymfonyAiHackathon2026Bundle\\ProductTranslation\\Tool\\',
        ]]]);
    }

    /**
     * @param array{fraud_checker_agent: array{enabled: bool, every: string, platform: string, model: string, max_output_tokens: int, disposable_domains: list<string>}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $fraud = $config['fraud_checker_agent'];

        $container->parameters()
            ->set(self::ALIAS . '.demos_directory', $this->getPath() . '/demos')
            ->set(self::ALIAS . '.fraud.disposable_domains', $fraud['disposable_domains'])
            ->set(self::ALIAS . '.fraud.model', $fraud['model'])
            ->set(self::ALIAS . '.fraud.max_output_tokens', $fraud['max_output_tokens'])
            // Not enabled: neither woken by a new account nor on the schedule, still run by hand.
            ->set(self::ALIAS . '.fraud.every', $fraud['enabled'] ? $fraud['every'] : null)
            ->set(self::ALIAS . '.fraud.enabled', $fraud['enabled'])
        ;

        $container->import('../config/services.php');

        // A service chosen by the host: a reference, not a parameter.
        $builder->getDefinition(self::ALIAS . '.fraud.judge')->setArgument('$platform', new Reference($fraud['platform']));
    }
}
