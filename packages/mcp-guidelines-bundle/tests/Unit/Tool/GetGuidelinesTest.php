<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Unit\Tool;

use Emagma\McpGuidelinesBundle\GuidelineLoaderInterface;
use Emagma\McpGuidelinesBundle\GuidelineResolver;
use Emagma\McpGuidelinesBundle\Tool\GetGuidelines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(GetGuidelines::class)]
final class GetGuidelinesTest extends TestCase
{
    public function testBeforeWritingAProductPageTheModelGetsItsGuidelines(): void
    {
        $answer = $this->tool()(['product_description']);

        self::assertSame([
            ['name' => 'company', 'content' => 'We care.'],
            ['name' => 'catalog_content', 'content' => 'Facts a buyer needs.'],
        ], $answer['guidelines']);
        self::assertArrayNotHasKey('unknown_intents', $answer);
    }

    public function testAnUnknownIntentIsReportedWithTheValidOnes(): void
    {
        $answer = $this->tool()(['product_description', 'poem']);

        self::assertCount(2, $answer['guidelines']);
        self::assertSame(['poem'], $answer['unknown_intents']);
        self::assertSame(['product_description'], $answer['intents']);
    }

    private function tool(): GetGuidelines
    {
        $texts = ['company.md' => 'We care.', 'catalog_content.md' => 'Facts a buyer needs.'];
        $loader = $this->createStub(GuidelineLoaderInterface::class);
        $loader->method('load')->willReturnCallback(static fn (string $resource): ?string => $texts[$resource] ?? null);

        return new GetGuidelines(new GuidelineResolver(
            new ServiceLocator(['markdown' => static fn () => $loader]),
            [
                ['name' => 'company', 'loader' => 'markdown', 'resource' => 'company.md'],
                ['name' => 'catalog_content', 'loader' => 'markdown', 'resource' => 'catalog_content.md'],
            ],
            ['product_description' => ['description' => 'Product written content', 'guidelines' => ['company', 'catalog_content']]],
        ));
    }
}
