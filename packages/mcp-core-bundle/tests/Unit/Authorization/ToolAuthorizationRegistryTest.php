<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Authorization;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\AllowAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\DenyAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\Deprecated;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\ToolAuthorizationRegistry;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Tests\Unit\Authorization\Fixture\Needs;
use Emagma\McpCoreBundle\Tests\Unit\Authorization\Fixture\NeedsGate;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(ToolAuthorizationRegistry::class)]
final class ToolAuthorizationRegistryTest extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    public function testToolsListIsFilteredToTheCallerGrants(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        $names = array_map(
            static fn (Tool $tool): string => $tool->name,
            $registry->getTools()->references,
        );

        self::assertSame(['list_products'], array_values($names));
    }

    public function testHasToolAnswersFalseForAForbiddenToolAsForAnUnknownOne(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        self::assertTrue($registry->hasTool('list_products'));
        self::assertFalse($registry->hasTool('delete_product'));
        self::assertFalse($registry->hasTool('no_such_tool'));
    }

    public function testWriteGrantExposesReadAndWriteToolsButNotDelete(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:write']));

        $names = array_values(array_map(
            static fn (Tool $tool): string => $tool->name,
            $registry->getTools()->references,
        ));

        self::assertContains('list_products', $names);
        self::assertContains('update_product', $names);
        self::assertNotContains('delete_product', $names);
    }

    public function testListedToolsCarryAnnotationsDerivedFromTheirPermissions(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read', 'mcp:sylius:product:write', 'mcp:sylius:product:delete']));

        $byName = [];
        foreach ($registry->getTools()->references as $tool) {
            \assert($tool instanceof Tool);
            $byName[$tool->name] = $tool->annotations?->jsonSerialize();
        }

        self::assertSame(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $byName['list_products']);
        self::assertSame(['readOnlyHint' => false, 'openWorldHint' => false], $byName['update_product']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], $byName['delete_product']);
    }

    public function testADeprecatedToolIsHiddenFromTheListAndStillServed(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read', 'mcp:sylius:product:write', 'mcp:sylius:product:delete']));

        $names = array_values(array_map(static fn (Tool $tool): string => $tool->name, $registry->getTools()->references));

        self::assertNotContains('list_products_legacy', $names);
        self::assertContains('list_products', $names);
        self::assertSame('list_products_legacy', $registry->getTool('list_products_legacy')->tool->name);
        self::assertSame('notice', $this->logRecords[0]['level']);
        self::assertSame('list_products', $this->logRecords[0]['context']['replacedBy']);
    }

    public function testAToolAGateRefusesIsHiddenWhateverTheGrants(): void
    {
        $names = $this->names($this->registry(Grants::fromStrings(['mcp:all:all:read', 'mcp:all:all:write', 'mcp:all:all:delete'])));

        self::assertNotContains('search_cms_pages', $names);
        self::assertNotContains('search_cms_pages_legacy', $names);
        self::assertContains('list_products', $names);
    }

    public function testAToolNoGateRefusesIsListedUnderItsGrants(): void
    {
        $active = ['cms', 'refunds'];

        self::assertSame(
            ['search_cms_pages'],
            $this->names($this->registry(Grants::fromStrings(['mcp:sylius:cms_page:read']), available: $active)),
            'listed, its deprecated sibling still hidden',
        );
        self::assertNotContains(
            'search_cms_pages',
            $this->names($this->registry(Grants::fromStrings(['mcp:sylius:product:read']), available: $active)),
            'what the backend has grants nothing by itself',
        );
    }

    public function testACallToAToolAGateRefusesAnswersTheRefusal(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:cms_page:read']));

        // Arguments the real tool's schema rejects: the answer must still be the reason, not a schema error.
        $handler = new CallToolHandler($registry, new ReferenceHandler());
        $session = $this->createStub(SessionInterface::class);
        foreach ([[], ['code' => 42]] as $id => $arguments) {
            $response = $handler->handle(new CallToolRequest('search_cms_pages', $arguments)->withId($id + 1), $session);

            self::assertInstanceOf(Response::class, $response);
            self::assertInstanceOf(CallToolResult::class, $response->result);
            $result = $response->result->structuredContent;
            self::assertIsArray($result);
            self::assertFalse($result['ok']);
            self::assertSame('backend_lacks', $result['reason']);
            self::assertSame('cms', $result['what']);
            self::assertArrayHasKey('hint', $result);
        }
        self::assertCount(2, $this->logRecords);
        self::assertSame('notice', $this->logRecords[0]['level']);
        self::assertSame('cms', $this->logRecords[0]['context']['what']);
        self::assertSame('search_cms_pages', $this->logRecords[0]['context']['tool']);
    }

    public function testTheGrantsAreCheckedBeforeTheGates(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        $this->expectException(ToolNotFoundException::class);

        try {
            $registry->getTool('search_cms_pages');
        } finally {
            self::assertCount(1, $this->logRecords);
            self::assertSame('warning', $this->logRecords[0]['level']);
        }
    }

    public function testAToolNoGateRefusesIsServedAsIs(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:cms_page:read']), available: ['cms', 'refunds']);

        self::assertIsArray($registry->getTool('search_cms_pages')->handler);
        self::assertSame([], $this->logRecords);
    }

    public function testADeprecatedToolAGateRefusesIsRefusedNotServed(): void
    {
        $inactive = $this->registry(Grants::fromStrings(['mcp:sylius:cms_page:read']));
        self::assertInstanceOf(\Closure::class, $inactive->getTool('search_cms_pages_legacy')->handler);

        $active = $this->registry(Grants::fromStrings(['mcp:sylius:cms_page:read']), available: ['cms', 'refunds']);
        self::assertIsArray($active->getTool('search_cms_pages_legacy')->handler, 'deprecated: still served once the backend has what it needs');
    }

    public function testWhatOneToolNeedsDoesNotHideTheOthers(): void
    {
        $grants = Grants::fromStrings(['mcp:all:all:write']);

        self::assertContains('refund_payment', $this->names($this->registry($grants, available: ['refunds'])));
        self::assertNotContains('refund_payment', $this->names($this->registry($grants, available: [])));
        self::assertContains('list_products', $this->names($this->registry($grants, available: [])));
    }

    public function testTheRefusalIsAnsweredBeforeTheToolsOwnSchemaIsChecked(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:payment:write']), available: []);

        // Arguments the real tool's schema rejects: the answer must still be the reason, not a schema error.
        $response = new CallToolHandler($registry, new ReferenceHandler())
            ->handle(new CallToolRequest('refund_payment', [])->withId(1), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        $result = $response->result->structuredContent;
        self::assertIsArray($result);
        self::assertFalse($result['ok']);
        self::assertSame('backend_lacks', $result['reason']);
        self::assertSame('refunds', $result['what']);
        self::assertSame('notice', $this->logRecords[0]['level']);
    }

    public function testAForbiddenToolAGateRefusesIsStillNotFound(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']), available: []);

        $this->expectException(ToolNotFoundException::class);

        $registry->getTool('refund_payment');
    }

    public function testForbiddenToolCallIsRejectedAsNotFoundAndLogged(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        $this->expectException(ToolNotFoundException::class);

        try {
            $registry->getTool('update_product');
        } finally {
            self::assertCount(1, $this->logRecords);
            self::assertSame('warning', $this->logRecords[0]['level']);
            self::assertSame('update_product', $this->logRecords[0]['context']['tool']);
            self::assertSame(['sylius:product:write'], $this->logRecords[0]['context']['required']);
        }
    }

    public function testAllowedToolCallPassesThrough(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        self::assertSame('list_products', $registry->getTool('list_products')->tool->name);
        self::assertSame([], $this->logRecords);
    }

    public function testUndeclaredToolIsFailClosedEvenWithWideGrants(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:all:all:read', 'mcp:all:all:write', 'mcp:all:all:delete']));

        $names = array_values(array_map(
            static fn (Tool $tool): string => $tool->name,
            $registry->getTools()->references,
        ));
        self::assertNotContains('undeclared_tool', $names);

        $this->expectException(ToolNotFoundException::class);
        $registry->getTool('undeclared_tool');
    }

    public function testDenyAllProviderExposesNothing(): void
    {
        $registry = $this->registry(null, new DenyAllGrantsProvider());

        self::assertSame([], $registry->getTools()->references);
    }

    public function testMultiPermissionToolNeedsEveryGrant(): void
    {
        $partial = $this->registry(Grants::fromStrings(['mcp:sylius:product:write']));
        $names = array_values(array_map(
            static fn (Tool $tool): string => $tool->name,
            $partial->getTools()->references,
        ));
        self::assertNotContains('update_product_taxons', $names);

        $full = $this->registry(Grants::fromStrings(['mcp:sylius:product:write', 'mcp:sylius:taxon:read']));
        self::assertSame(
            'update_product_taxons',
            $full->getTool('update_product_taxons')->tool->name,
        );
    }

    public function testNonToolSurfacesAreFailClosedUnderRealGrants(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:all:all:read', 'mcp:all:all:write', 'mcp:all:all:delete']));

        // The one resource such grants open is the document of an app whose tool they serve.
        self::assertSame(['ui://stock'], array_map(static fn (ResourceDefinition $resource): string => $resource->uri, $registry->getResources()->references));
        self::assertSame([], $registry->getResourceTemplates()->references);
        self::assertSame([], $registry->getPrompts()->references);
        self::assertFalse($registry->hasPrompts());
        self::assertFalse($registry->hasResource('file://catalog.txt'));
        self::assertFalse($registry->hasPrompt('shop_summary'));

        try {
            $registry->getResource('file://catalog.txt');
            self::fail('resource read should be denied');
        } catch (ResourceNotFoundException) {
        }

        $this->expectException(PromptNotFoundException::class);
        $registry->getPrompt('shop_summary');
    }

    public function testTheDocumentOfAnAppIsServedToWhoeverIsServedOneOfItsTools(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:stock:read']));

        self::assertTrue($registry->hasResources());
        self::assertTrue($registry->hasResource('ui://stock'));
        self::assertSame(['ui://stock'], array_map(static fn (ResourceDefinition $resource): string => $resource->uri, $registry->getResources()->references));
        self::assertInstanceOf(ResourceReference::class, $registry->getResource('ui://stock'));
        self::assertFalse($registry->hasResource('file://catalog.txt'), 'any other resource stays closed');
    }

    public function testTheDocumentOfAnAppIsClosedToWhoeverCannotCallItsTools(): void
    {
        $registry = $this->registry(Grants::fromStrings(['mcp:sylius:product:read']));

        self::assertFalse($registry->hasResources());
        self::assertFalse($registry->hasResource('ui://stock'));
        self::assertSame([], $registry->getResources()->references);

        $this->expectException(ResourceNotFoundException::class);
        $registry->getResource('ui://stock');
    }

    public function testNonToolSurfacesAreServedUnderExplicitAllowAll(): void
    {
        $registry = $this->registry(null, new AllowAllGrantsProvider());

        self::assertCount(2, $registry->getResources()->references);
        self::assertCount(1, $registry->getPrompts()->references);
        $reference = $registry->getResource('file://catalog.txt');
        self::assertInstanceOf(ResourceReference::class, $reference);
        self::assertSame('file://catalog.txt', $reference->resource->uri);
        self::assertSame('shop_summary', $registry->getPrompt('shop_summary')->prompt->name);
    }

    /**
     * @return list<string>
     */
    private function names(ToolAuthorizationRegistry $registry): array
    {
        $names = [];
        foreach ($registry->getTools()->references as $item) {
            if ($item instanceof Tool) {
                $names[] = $item->name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $available
     */
    private function registry(?Grants $grants, ?GrantsProviderInterface $provider = null, array $available = ['refunds']): ToolAuthorizationRegistry
    {
        $inner = new Registry();
        $inner->registerTool(
            new Tool('list_products', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'product', action: Action::Read)] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('update_product', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'product', action: Action::Write)] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('delete_product', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'product', action: Action::Delete)] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('update_product_taxons', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'product', action: Action::Write)] #[Permission(resource: 'taxon', action: Action::Read)] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('list_products_legacy', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'product', action: Action::Read)] #[Deprecated(replacedBy: 'list_products')] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('search_cms_pages', null, ['type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code']], null, null),
            [new #[Permission(resource: 'cms_page', action: Action::Read)] #[Needs('cms')] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('search_cms_pages_legacy', null, ['type' => 'object'], null, null),
            [new #[Permission(resource: 'cms_page', action: Action::Read)] #[Needs('cms')] #[Deprecated(replacedBy: 'search_cms_pages')] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('refund_payment', null, ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']], null, null),
            [new #[Permission(resource: 'payment', action: Action::Write)] #[Needs('refunds')] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerTool(
            new Tool('undeclared_tool', null, ['type' => 'object'], null, null),
            [new class() {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerResource(
            new ResourceDefinition('file://catalog.txt', 'catalog'),
            static fn (): string => 'catalog',
        );
        // An MCP App: a tool linked to its `ui://` document.
        $inner->registerTool(
            new Tool('open_stock_screen', null, ['type' => 'object'], null, null, meta: ['ui' => new UiToolMeta('ui://stock')]),
            [new #[Permission(resource: 'stock', action: Action::Read)] class {
                public function __invoke(): void
                {
                }
            }, '__invoke'],
        );
        $inner->registerResource(
            new ResourceDefinition('ui://stock', 'stock'),
            static fn (): string => '<html></html>',
        );
        $inner->registerPrompt(
            new Prompt('shop_summary'),
            static fn (): array => [],
        );

        $logger = new class($this->logRecords) extends AbstractLogger {
            /**
             * @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records
             */
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new ToolAuthorizationRegistry(
            $inner,
            new ToolPermissionResolver(['class@anonymous' => 'sylius']),
            $provider ?? new class($grants ?? Grants::none()) implements GrantsProviderInterface {
                public function __construct(private readonly Grants $grants)
                {
                }

                public function grants(): Grants
                {
                    return $this->grants;
                }
            },
            $logger,
            [new NeedsGate($available)],
        );
    }
}
