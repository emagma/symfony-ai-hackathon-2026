<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection;

use ApiPlatform\Metadata\ApiResource;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Emagma\SyliusMcpPlugin\ApiResource\Capabilities;
use Emagma\SyliusMcpPlugin\DependencyInjection\EmagmaSyliusMcpExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * What the plugin hands to the shop: the directories it prepends exist and
 * hold what the framework expects, and the services are reachable by class.
 */
#[CoversClass(EmagmaSyliusMcpExtension::class)]
final class PrependedConfigTest extends TestCase
{
    public function testTheSerializerMappingPathHoldsTheLeanMappings(): void
    {
        $container = new ContainerBuilder();
        (new EmagmaSyliusMcpExtension())->prepend($container);

        $paths = $container->getExtensionConfig('framework')[0]['serializer']['mapping']['paths'];
        self::assertCount(1, $paths);
        self::assertDirectoryExists($paths[0]);

        $files = glob($paths[0] . '/*.xml');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $groups = $this->groupsOf($file);
            self::assertNotEmpty($groups, $file);
            self::assertNotEmpty(
                array_filter($groups, static fn (string $group): bool => 1 === preg_match('/^mcp:[a-z_]+:lean$/', $group)),
                $file . ' maps no lean group',
            );
        }
    }

    public function testTheApiResourcePathHoldsTheCapabilitiesResourceOnly(): void
    {
        $container = new ContainerBuilder();
        (new EmagmaSyliusMcpExtension())->prepend($container);

        $paths = $container->getExtensionConfig('api_platform')[0]['mapping']['paths'];
        self::assertCount(1, $paths);
        self::assertSame(['Capabilities.php'], array_map('basename', glob($paths[0] . '/*.php') ?: []));
    }

    public function testTheCapabilitiesResourceNamesTheProviderServiceById(): void
    {
        $attributes = (new \ReflectionClass(Capabilities::class))->getAttributes(ApiResource::class);
        self::assertCount(1, $attributes);

        $operation = $attributes[0]->newInstance()->getOperations();
        self::assertNotNull($operation);
        foreach ($operation as $get) {
            self::assertSame(EmagmaSyliusMcpExtension::CAPABILITIES_PROVIDER_ID, $get->getProvider());
        }
    }

    public function testTheRequestDetectorIsReachableByClass(): void
    {
        $container = new ContainerBuilder();
        $extension = new EmagmaSyliusMcpExtension();
        $extension->load([], $container);

        self::assertTrue($container->hasAlias(LeanRequestDetector::class));
        self::assertSame(
            'emagma_sylius_mcp.lean.request_detector',
            (string) $container->getAlias(LeanRequestDetector::class),
        );
    }

    /**
     * @return list<string>
     */
    private function groupsOf(string $file): array
    {
        $dom = new \DOMDocument();
        $dom->load($file);
        $groups = [];
        foreach ($dom->getElementsByTagName('group') as $node) {
            $groups[] = trim((string) $node->textContent);
        }

        return $groups;
    }
}
