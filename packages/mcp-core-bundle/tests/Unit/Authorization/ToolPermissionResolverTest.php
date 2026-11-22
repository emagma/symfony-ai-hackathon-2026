<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Authorization\WidenedBy;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolPermissionResolver::class)]
final class ToolPermissionResolverTest extends TestCase
{
    public function testResolvesRepeatedAttributesFromHandlerObject(): void
    {
        $handler = new #[Permission(resource: 'product', action: Action::Write)] #[Permission(resource: 'taxon', action: Action::Read)] class {
            public function __invoke(): void
            {
            }
        };

        $permissions = (new ToolPermissionResolver())->resolve($this->reference([$handler, '__invoke']));

        self::assertCount(2, $permissions);
        self::assertSame('product', $permissions[0]->resource);
        self::assertSame(Action::Write, $permissions[0]->action);
        self::assertSame('taxon', $permissions[1]->resource);
        self::assertSame(Action::Read, $permissions[1]->action);
    }

    public function testAPermissionTakesTheDomainOfItsNamespaceTheLongestPrefixFirst(): void
    {
        $resolver = new ToolPermissionResolver(['Acme\\' => 'acme', 'Acme\\Cms\\' => 'acme_cms']);

        self::assertSame('acme', $resolver->domainOf('Acme\\Tool\\ListProducts'));
        self::assertSame('acme_cms', $resolver->domainOf('Acme\\Cms\\Tool\\ListPages'));
        self::assertNull($resolver->domainOf('Other\\Tool'), 'a namespace listed nowhere has no domain, hence no grant that fits');
    }

    public function testADomainSetOnTheAttributeIsKept(): void
    {
        $handler = new #[Permission(resource: 'page', action: Action::Write)] #[Permission(resource: 'product', action: Action::Read, domain: 'sylius')] class {
            public function __invoke(): void
            {
            }
        };

        $permissions = (new ToolPermissionResolver(['class@anonymous' => 'sylius_cms']))->resolve($this->reference([$handler, '__invoke']));

        self::assertSame(['sylius_cms:page:write', 'sylius:product:read'], array_map(static fn (Permission $p): string => $p->name(), $permissions));
    }

    public function testUndeclaredHandlerResolvesToNothing(): void
    {
        $handler = new class() {
            public function __invoke(): void
            {
            }
        };

        self::assertSame([], (new ToolPermissionResolver())->resolve($this->reference([$handler, '__invoke'])));
    }

    public function testClosureHandlerResolvesToNothing(): void
    {
        $reference = $this->reference(static function (): void {});

        self::assertSame([], (new ToolPermissionResolver())->resolve($reference));
    }

    public function testAWideningIsNotRequiredAndTakesTheDomainOfTheTool(): void
    {
        $handler = new #[Permission(resource: 'audit', action: Action::Read)] #[WidenedBy(resource: 'audit_all', action: Action::Read)] class {
            public function __invoke(): void
            {
            }
        };
        $resolver = new ToolPermissionResolver(['class@anonymous' => 'core']);

        self::assertSame(['core:audit:read'], array_map(static fn (Permission $p): string => $p->name(), $resolver->resolve($this->reference([$handler, '__invoke']))));
        self::assertSame(['core:audit_all:read'], array_map(static fn (Permission $p): string => $p->name(), $resolver->wideningOf($handler::class)));
        self::assertSame($resolver->resolve($this->reference([$handler, '__invoke'])), $resolver->permissionsOf($handler::class));
    }

    public function testResolutionIsCachedPerClass(): void
    {
        $handler = new #[Permission(resource: 'zone', action: Action::Read)] class {
            public function __invoke(): void
            {
            }
        };
        $resolver = new ToolPermissionResolver();

        $first = $resolver->resolve($this->reference([$handler, '__invoke']));
        $second = $resolver->resolve($this->reference([$handler, '__invoke']));

        self::assertSame($first, $second);
    }

    /**
     * @param \Closure|array{0: object, 1: string}|string $handler
     */
    private function reference(\Closure|array|string $handler): ToolReference
    {
        return new ToolReference(
            new Tool('a_tool', null, ['type' => 'object'], null, null),
            $handler,
        );
    }
}
