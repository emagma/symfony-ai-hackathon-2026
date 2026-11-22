<?php

declare(strict_types=1);

use Emagma\McpSkillsBundle\SkillRepository;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/*
 * Explicit definitions, ids prefixed by the alias. The tools, loaded by namespace, autowire the repository
 * through its class alias.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Read again on every request: a skill edited on disk needs no restart.
    $services->set('emagma_mcp_skills.repository', SkillRepository::class)
        ->args([param('emagma_mcp_skills.directory')])
        ->tag('kernel.reset', ['method' => 'forget']);
    $services->alias(SkillRepository::class, 'emagma_mcp_skills.repository');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpSkillsBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure()
        ->bind('$pack', param('emagma_mcp_skills.pack'));
};
