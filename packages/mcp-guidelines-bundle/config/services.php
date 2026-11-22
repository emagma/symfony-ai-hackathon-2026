<?php

declare(strict_types=1);

use Emagma\McpGuidelinesBundle\Command\ListGuidelinesCommand;
use Emagma\McpGuidelinesBundle\GuidelineResolver;
use Emagma\McpGuidelinesBundle\Instruction\GuidelinesInstruction;
use Emagma\McpGuidelinesBundle\Loader\MarkdownGuidelineLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The tool, loaded by namespace, autowires the resolver
 * through its class alias.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_guidelines.loader.markdown', MarkdownGuidelineLoader::class)
        ->tag('emagma_mcp_guidelines.loader', ['alias' => 'markdown']);

    // The loaders, the resources and the intents are set by the bundle then GuidelinesPass.
    $services->set('emagma_mcp_guidelines.resolver', GuidelineResolver::class)
        ->args([
            '',
            [],
            [],
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(GuidelineResolver::class, 'emagma_mcp_guidelines.resolver');

    // After what the server is (the backend's conventions, priority 100): priority 0.
    $services->set('emagma_mcp_guidelines.instruction', GuidelinesInstruction::class)
        ->args([
            [],
        ])
        ->tag('emagma_mcp_core.instruction', ['priority' => 0]);

    $services->set('emagma_mcp_guidelines.command.list', ListGuidelinesCommand::class)
        ->args([
            service('emagma_mcp_guidelines.resolver'),
        ])
        ->tag('console.command');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpGuidelinesBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
};
