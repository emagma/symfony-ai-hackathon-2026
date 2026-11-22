<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Unit\DependencyInjection;

use Emagma\McpGuidelinesBundle\DependencyInjection\Compiler\GuidelinesPass;
use Emagma\McpGuidelinesBundle\EmagmaMcpGuidelinesBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(GuidelinesPass::class)]
#[CoversClass(EmagmaMcpGuidelinesBundle::class)]
final class GuidelinesPassTest extends TestCase
{
    private const string BUNDLE = '@EmagmaMcpGuidelinesBundle/resources/guidelines/';

    public function testInstalledAloneItReadsItsSixGuidelinesAndHasNoIntent(): void
    {
        $container = $this->build([]);

        $resources = $container->getDefinition('emagma_mcp_guidelines.resolver')->getArgument(1);
        self::assertSame(EmagmaMcpGuidelinesBundle::DEFAULTS, array_column($resources, 'name'));
        self::assertSame(['markdown'], array_values(array_unique(array_column($resources, 'loader'))));
        self::assertFileExists($resources[0]['resource']);
        self::assertSame([], $container->getDefinition('emagma_mcp_guidelines.instruction')->getArgument(0), 'no intent: nothing told to the model');
    }

    public function testAResourceIsNamedAfterItsFileAndAnotherLoaderNamesIt(): void
    {
        $container = $this->build([[
            'resources' => [
                self::BUNDLE . 'company.md',
                ['resource' => 'https://wiki.example.com/legal', 'loader' => 'wiki', 'name' => 'legal_notice'],
            ],
            'intents' => ['customer_reply' => ['description' => 'Writing to a customer', 'guidelines' => ['company', 'legal_notice']]],
        ]]);

        $resources = $container->getDefinition('emagma_mcp_guidelines.resolver')->getArgument(1);
        self::assertSame(['company', 'legal_notice'], array_column($resources, 'name'));
        self::assertSame(['markdown', 'wiki'], array_column($resources, 'loader'));
    }

    public function testAResourceNoLoaderReadsStopsTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no loader reads "https://wiki.example.com/legal". Known loaders: markdown, wiki.');

        $this->build([['resources' => ['https://wiki.example.com/legal']]]);
    }

    public function testAMissingFileStopsTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->build([['resources' => [self::BUNDLE . 'nope.md']]]);
    }

    public function testTwoResourcesOfTheSameNameStopTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('two resources are named "company"');

        $this->build([['resources' => [self::BUNDLE . 'company.md', ['resource' => 'x', 'loader' => 'wiki', 'name' => 'company']]]]);
    }

    public function testAnIntentNamingAnUnknownGuidelineStopsTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('emagma_mcp_guidelines.intents.product_description names unknown guidelines: brand.');

        $this->build([['intents' => ['product_description' => ['description' => 'Product written content', 'guidelines' => ['company', 'brand']]]]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function build(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir()]));
        $container->setParameter('kernel.bundles_metadata', ['EmagmaMcpGuidelinesBundle' => ['path' => \dirname(__DIR__, 3), 'namespace' => 'Emagma\\McpGuidelinesBundle']]);
        (new EmagmaMcpGuidelinesBundle())->getContainerExtension()?->load($configs, $container);
        $container->register('app.wiki_loader')->addTag(EmagmaMcpGuidelinesBundle::LOADER_TAG, ['alias' => 'wiki']);
        (new GuidelinesPass())->process($container);

        return $container;
    }
}
