<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\DependencyInjection;

use Emagma\SyliusMcpPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testNothingConfiguredGivesEmptyMaps(): void
    {
        self::assertSame(
            ['lean' => ['groups' => [], 'filters' => []], 'extensions' => []],
            $this->process([]),
        );
    }

    public function testContributionsMergePerClassAndUriTemplateAndAppendFilterIds(): void
    {
        $config = $this->process([
            [
                'lean' => [
                    'groups' => ['%acme.model.page.class%' => 'mcp:cms_page:lean'],
                    'filters' => ['%acme.model.page.class%' => ['/admin/cms/pages' => ['filter.a']]],
                ],
                'extensions' => ['sylius_cms' => 'sylius/cms-plugin'],
            ],
            [
                'lean' => [
                    'groups' => ['Acme\Block' => 'mcp:cms_block:lean'],
                    'filters' => ['%acme.model.page.class%' => [
                        '/admin/cms/pages' => ['filter.b'],
                        '/admin/cms-archived-pages' => ['filter.c'],
                    ]],
                ],
                'extensions' => ['monsieurbiz_cms' => 'monsieurbiz/sylius-cms-page-plugin'],
            ],
        ]);

        self::assertSame(
            ['%acme.model.page.class%' => 'mcp:cms_page:lean', 'Acme\Block' => 'mcp:cms_block:lean'],
            $config['lean']['groups'],
        );
        // Keys are kept verbatim: no `-` → `_` normalization.
        self::assertSame(
            ['%acme.model.page.class%' => [
                '/admin/cms/pages' => ['filter.a', 'filter.b'],
                '/admin/cms-archived-pages' => ['filter.c'],
            ]],
            $config['lean']['filters'],
        );
        self::assertSame(
            ['sylius_cms' => 'sylius/cms-plugin', 'monsieurbiz_cms' => 'monsieurbiz/sylius-cms-page-plugin'],
            $config['extensions'],
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'extension name not snake_case' => [['extensions' => ['Sylius-CMS' => 'sylius/cms-plugin']]];
        yield 'package without vendor' => [['extensions' => ['sylius_cms' => 'cms-plugin']]];
        yield 'empty lean group' => [['lean' => ['groups' => ['Acme\Page' => '']]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testItRejects(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([$config]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array{lean: array{groups: array<string, string>, filters: array<string, array<string, list<string>>>}, extensions: array<string, string>}
     */
    private function process(array $configs): array
    {
        /** @var array{lean: array{groups: array<string, string>, filters: array<string, array<string, list<string>>>}, extensions: array<string, string>} $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), $configs);

        return $processed;
    }
}
