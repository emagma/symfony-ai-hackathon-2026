<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Unit\DependencyInjection;

use Emagma\McpKnowledgeBundle\EmagmaMcpKnowledgeBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(EmagmaMcpKnowledgeBundle::class)]
final class BundleConfigurationTest extends TestCase
{
    public function testWithoutEmbeddingsTheIndexHasNoVectorizerAndTheDirectoryFallsBackToTheProjects(): void
    {
        $container = $this->load([]);

        $index = $container->getDefinition('emagma_mcp_knowledge.index');
        self::assertSame('%env(resolve:MCP_KNOWLEDGE_DIR)%', $index->getArgument(2));
        self::assertSame('%kernel.project_dir%/var/knowledge/index.sqlite.manifest.json', $index->getArgument(3));
        self::assertNull($index->getArgument(4));
        self::assertSame('%kernel.project_dir%/var/knowledge/index.sqlite', $container->getDefinition('emagma_mcp_knowledge.pdo')->getArgument(0));
        self::assertSame('%kernel.project_dir%/knowledge', $container->getParameter('env(MCP_KNOWLEDGE_DIR)'));
    }

    public function testAPlatformAndAModelMakeTheSearchHybrid(): void
    {
        $container = $this->load(['embeddings' => ['platform' => 'ai.platform.mistral', 'model' => 'mistral-embed']]);

        $vectorizer = $container->getDefinition('emagma_mcp_knowledge.index')->getArgument(4);
        self::assertInstanceOf(Definition::class, $vectorizer);
        self::assertSame(Vectorizer::class, $vectorizer->getClass());
        self::assertEquals(new Reference('ai.platform.mistral'), $vectorizer->getArgument(0));
        self::assertSame('mistral-embed', $vectorizer->getArgument(1));
    }

    public function testAPlatformWithoutAModelStaysByWords(): void
    {
        $container = $this->load(['embeddings' => ['platform' => 'ai.platform.mistral']]);

        self::assertNull($container->getDefinition('emagma_mcp_knowledge.index')->getArgument(4));
    }

    public function testTheDirectoryVariableTheHostDefinesIsLeftAlone(): void
    {
        $container = new ContainerBuilder(new ParameterBag());
        $container->setParameter('env(MCP_KNOWLEDGE_DIR)', '/srv/procedures');

        $this->load([], $container);

        self::assertSame('/srv/procedures', $container->getParameter('env(MCP_KNOWLEDGE_DIR)'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= new ContainerBuilder(new ParameterBag());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        (new EmagmaMcpKnowledgeBundle())->getContainerExtension()?->load([$config], $container);

        return $container;
    }
}
