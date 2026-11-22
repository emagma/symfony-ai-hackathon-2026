<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Integration;

use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Emagma\SymfonyAiHackathon2026Bundle\EmagmaSymfonyAiHackathon2026Bundle;
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\Tool\ProductTranslate;
use Emagma\SymfonyAiHackathon2026Bundle\Tests\Integration\Fixture\TestKernel;
use Emagma\SymfonyAiHackathon2026Bundle\Tool\GetDemo;
use Emagma\SymfonyAiHackathon2026Bundle\Tool\ListDemos;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** The bundle as the container of a host builds it: every reference checked at compile time, the tools, the grants, the settings. */
#[CoversClass(EmagmaSymfonyAiHackathon2026Bundle::class)]
final class WiringTest extends TestCase
{
    public function testTheDemosAreTheBundlesOwnFilesAndTheirToolsAreOnTheServer(): void
    {
        $container = $this->container();

        $demos = $container->get('test.demos');
        self::assertInstanceOf(DemoRepository::class, $demos);
        self::assertCount(8, $demos->all());

        $tools = $container->get('test.tool_class_map');
        self::assertInstanceOf(ToolClassMap::class, $tools);
        self::assertSame(ListDemos::class, $tools->classOf('list_demos'));
        self::assertSame(GetDemo::class, $tools->classOf('get_demo'));
        self::assertSame(ProductTranslate::class, $tools->classOf('product_translate'));
    }

    public function testProductTranslateKeepsItsGrantsOfTheSyliusDomainAndTheDemosAreInCore(): void
    {
        $resolver = $this->container()->get('test.permission_resolver');
        self::assertInstanceOf(ToolPermissionResolver::class, $resolver);

        self::assertSame(
            ['sylius:product:write', 'core:task:write', 'core:llm:read'],
            array_map(static fn (Permission $permission): string => $permission->name(), $resolver->resolve($this->reference(ProductTranslate::class))),
        );
        self::assertSame(
            ['core:demo:read'],
            array_map(static fn (Permission $permission): string => $permission->name(), $resolver->resolve($this->reference(ListDemos::class))),
        );
    }

    public function testByDefaultTheAgentRunsEveryFiveMinutesAndIsWokenByEachAccount(): void
    {
        $container = $this->container();

        self::assertSame('5 minutes', $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.every'));
        self::assertTrue($container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.enabled'));
        self::assertSame(EmagmaSymfonyAiHackathon2026Bundle::DISPOSABLE_DOMAINS, $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.disposable_domains'));
        self::assertSame('claude-haiku-4-5', $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.model'));
        self::assertSame(500, $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.max_output_tokens'));
    }

    public function testAnAgentNotEnabledIsLeftOffTheScheduleAndWokenByNoAccount(): void
    {
        $container = $this->container(['enabled' => false, 'every' => '1 minute', 'model' => 'claude-sonnet-4-5', 'disposable_domains' => ['yopmail.com']]);

        self::assertNull($container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.every'));
        self::assertFalse($container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.enabled'));
        self::assertSame(['yopmail.com'], $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.disposable_domains'));
        self::assertSame('claude-sonnet-4-5', $container->getParameter('emagma_symfony_ai_hackathon_2026.fraud.model'));
    }

    /**
     * @param array<string, mixed> $fraud
     */
    private function container(array $fraud = []): ContainerInterface
    {
        $kernel = new TestKernel($fraud);
        $kernel->boot();

        return $kernel->getContainer();
    }

    /**
     * @param class-string $class
     */
    private function reference(string $class): ToolReference
    {
        return new ToolReference(new Tool('a_tool', null, ['type' => 'object'], null, null), [$class, '__invoke']);
    }
}
