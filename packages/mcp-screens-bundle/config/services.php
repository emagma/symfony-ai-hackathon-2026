<?php

declare(strict_types=1);

use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpScreensBundle\App\ScreenApp;
use Emagma\McpScreensBundle\EmagmaMcpScreensBundle;
use Emagma\McpScreensBundle\Instruction\ScreensInstruction;
use Emagma\McpScreensBundle\ScreenRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Explicit definitions, ids prefixed by the alias. The app is the unit symfony/mcp-bundle discovers
 * (its attributes, hence autoconfigure): one class, defined with its arguments.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('emagma_mcp_screens.registry', ScreenRegistry::class)
        ->args([
            tagged_iterator(EmagmaMcpScreensBundle::SCREEN_TAG),
        ]);
    $services->alias(ScreenRegistry::class, 'emagma_mcp_screens.registry');

    // The screens in the server's instructions (the core assembles them), with the business: priority 0.
    $services->set('emagma_mcp_screens.instruction', ScreensInstruction::class)
        ->args([
            service('emagma_mcp_screens.registry'),
        ])
        ->tag('emagma_mcp_core.instruction', ['priority' => 0]);

    // The id is the class: the SDK resolves the handler of the app's tools by it.
    $services->set(ScreenApp::class)
        ->args([
            service('emagma_mcp_screens.registry'),
            service(GrantsProviderInterface::class),
            service('twig'),
        ])
        ->autoconfigure();
};
