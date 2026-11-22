<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Unit;

use Emagma\McpGuidelinesBundle\Guideline;
use Emagma\McpGuidelinesBundle\GuidelineLoaderInterface;
use Emagma\McpGuidelinesBundle\GuidelineResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(GuidelineResolver::class)]
final class GuidelineResolverTest extends TestCase
{
    public function testTheIntentsGiveTheirGuidelinesEachOnceInOrder(): void
    {
        $found = $this->resolver()->forIntents(['product_description', 'blog_post', 'poem']);

        self::assertSame(['company', 'catalog_content', 'website_post'], array_map(static fn (Guideline $g): string => $g->name, $found['guidelines']));
        self::assertSame(['poem'], $found['unknown']);
    }

    public function testAGuidelineThatCannotBeReadIsLeftOut(): void
    {
        self::assertSame(['company', 'catalog_content', 'website_post'], array_keys($this->resolver()->all()));
    }

    public function testEachResourceIsReadOncePerRequestAndAgainAfterAReset(): void
    {
        $loader = $this->createMock(GuidelineLoaderInterface::class);
        $loader->expects(self::exactly(2))->method('load')->willReturn('We care.');
        $resolver = new GuidelineResolver(new ServiceLocator(['markdown' => static fn () => $loader]), [['name' => 'company', 'loader' => 'markdown', 'resource' => 'company.md']], []);

        $resolver->forIntents([]);
        $resolver->all();
        $resolver->reset();
        $resolver->all();
    }

    private function resolver(): GuidelineResolver
    {
        $loader = $this->createStub(GuidelineLoaderInterface::class);
        $loader->method('load')->willReturnCallback(static fn (string $resource): ?string => 'missing.md' === $resource ? null : 'Text of ' . $resource);

        return new GuidelineResolver(
            new ServiceLocator(['markdown' => static fn () => $loader]),
            [
                ['name' => 'company', 'loader' => 'markdown', 'resource' => 'company.md'],
                ['name' => 'catalog_content', 'loader' => 'markdown', 'resource' => 'catalog_content.md'],
                ['name' => 'website_post', 'loader' => 'markdown', 'resource' => 'website_post.md'],
                ['name' => 'customer_message', 'loader' => 'markdown', 'resource' => 'missing.md'],
            ],
            [
                'product_description' => ['description' => 'Product written content', 'guidelines' => ['company', 'catalog_content']],
                'blog_post' => ['description' => 'Blog post, buying guide, marketing article', 'guidelines' => ['company', 'website_post']],
            ],
        );
    }
}
