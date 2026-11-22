<?php

declare(strict_types=1);

use Emagma\McpKnowledgeBundle\Command\IndexKnowledgeCommand;
use Emagma\McpKnowledgeBundle\Index\KnowledgeIndex;
use Emagma\McpKnowledgeBundle\Index\SqliteConnectionFactory;
use Emagma\McpKnowledgeBundle\Procedure\ProcedureLoader;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, ids prefixed by the alias. The tool, loaded by namespace, autowires the index
 * through its class alias.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The argument is the bundle's index_file. Lazy: the file is opened on the first search.
    $services->set('emagma_mcp_knowledge.pdo', \PDO::class)
        ->lazy()
        ->factory([SqliteConnectionFactory::class, 'create'])
        ->args(['']);

    $services->set('emagma_mcp_knowledge.store', Store::class)
        ->args([
            service('emagma_mcp_knowledge.pdo'),
            'mcp_knowledge',
        ]);

    $services->set('emagma_mcp_knowledge.procedure_loader', ProcedureLoader::class);

    // The directory, the manifest file and the vectorizer are the bundle's configuration.
    $services->set('emagma_mcp_knowledge.index', KnowledgeIndex::class)
        ->args([
            service('emagma_mcp_knowledge.store'),
            service('emagma_mcp_knowledge.procedure_loader'),
            '',
            '',
            null,
        ])
        ->tag('kernel.reset', ['method' => 'forgetSync']);
    $services->alias(KnowledgeIndex::class, 'emagma_mcp_knowledge.index');

    $services->set('emagma_mcp_knowledge.command.index', IndexKnowledgeCommand::class)
        ->args([service('emagma_mcp_knowledge.index')])
        ->tag('console.command');

    // The unit symfony/mcp-bundle discovers: loaded by namespace, autowired (spec D3).
    $services->load('Emagma\\McpKnowledgeBundle\\Tool\\', '../src/Tool/*')
        ->autowire()
        ->autoconfigure();
};
