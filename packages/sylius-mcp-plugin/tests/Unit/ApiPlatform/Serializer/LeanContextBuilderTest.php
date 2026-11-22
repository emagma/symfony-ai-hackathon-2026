<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Emagma\SyliusMcpPlugin\ApiPlatform\Serializer\LeanContextBuilder;
use Emagma\SyliusMcpPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LeanContextBuilder::class)]
final class LeanContextBuilderTest extends TestCase
{
    public function testACoreResourceGetsItsLeanGroupThroughItsParentClass(): void
    {
        $shopProduct = new class() extends Product {
        };

        $context = $this->builder(Configuration::CORE_LEAN_GROUPS)
            ->createFromRequest($this->leanRequest(), true, ['resource_class' => $shopProduct::class]);

        self::assertSame(['mcp:product:lean'], $context['groups']);
    }

    public function testAContributedGroupIsUsedForItsClass(): void
    {
        $map = [...Configuration::CORE_LEAN_GROUPS, \stdClass::class => 'mcp:cms_page:lean'];

        $context = $this->builder($map)
            ->createFromRequest($this->leanRequest(), true, ['resource_class' => \stdClass::class]);

        self::assertSame(['mcp:cms_page:lean'], $context['groups']);
    }

    public function testAnUnmappedClassOrANonLeanRequestKeepsTheFullContext(): void
    {
        $builder = $this->builder([Product::class => 'mcp:product:lean']);

        self::assertSame(['full'], $builder->createFromRequest($this->leanRequest(), true, ['resource_class' => Order::class])['groups']);
        self::assertSame(['full'], $builder->createFromRequest(new Request(), true, ['resource_class' => Product::class])['groups']);
        self::assertSame(['full'], $builder->createFromRequest($this->leanRequest(), false, ['resource_class' => Product::class])['groups']);
    }

    /**
     * @param array<string, string> $map
     */
    private function builder(array $map): LeanContextBuilder
    {
        $decorated = new class() implements SerializerContextBuilderInterface {
            /**
             * @param array<string, mixed>|null $extractedAttributes
             *
             * @return array<string, mixed>
             */
            public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
            {
                return ['resource_class' => $extractedAttributes['resource_class'] ?? null, 'groups' => ['full']];
            }
        };

        return new LeanContextBuilder($decorated, new LeanRequestDetector(new RequestStack()), $map);
    }

    private function leanRequest(): Request
    {
        $request = new Request();
        $request->headers->set('Prefer', 'respond-for=lean');

        return $request;
    }
}
