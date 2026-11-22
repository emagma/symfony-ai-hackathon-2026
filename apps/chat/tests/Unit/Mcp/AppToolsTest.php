<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Mcp;

use Emagma\SyliusAssistantTestChat\Mcp\AppTools;
use Emagma\SyliusAssistantTestChat\Mcp\FilteredToolset;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;

#[CoversClass(AppTools::class)]
#[CoversClass(FilteredToolset::class)]
final class AppToolsTest extends TestCase
{
    public function testAToolLinkedToAScreenIsKnownByItsNameAndByTheModelsName(): void
    {
        $apps = $this->apps();

        self::assertSame('ui://screens', $apps->uriOf('open_screen'));
        self::assertSame('ui://screens', $apps->uriOf('shop_open_screen'));
        self::assertNull($apps->uriOf('search_products'));
        self::assertNull($apps->uriOf('reopen_screen'), 'a suffix is a whole name after a prefix');
    }

    public function testAScreenCallsTheToolsOfItsOwnAppOnly(): void
    {
        $apps = $this->apps();

        self::assertTrue($apps->belongsTo('screen_submit', 'ui://screens'));
        self::assertTrue($apps->belongsTo('open_screen', 'ui://screens'));
        self::assertFalse($apps->belongsTo('screen_submit', 'ui://other'));
        self::assertFalse($apps->belongsTo('delete_products', 'ui://screens'), 'not a tool of any app');
    }

    public function testTheModelNeverSeesAToolOnlyAScreenMayCall(): void
    {
        $inner = $this->createStub(ToolsetInterface::class);
        $inner->method('getTools')->willReturn(self::tools());

        $names = static fn (FilteredToolset $toolset): array => array_map(static fn (Tool $tool): string => $tool->name, $toolset->getTools());

        self::assertSame(['search_products', 'open_screen', 'legacy_app'], $names(new FilteredToolset($inner, [], $this->apps())));
        self::assertSame(['open_screen'], $names(new FilteredToolset($inner, ['open_', 'screen_'], $this->apps())));
        self::assertCount(5, (new FilteredToolset($inner))->getTools(), 'without the reader, nothing is known about apps');
    }

    private function apps(): AppTools
    {
        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('getTools')->willReturn(self::tools());

        return new AppTools($connection);
    }

    /**
     * @return list<Tool>
     */
    private static function tools(): array
    {
        $tool = static fn (string $name, ?string $uri = null, string ...$visibility): Tool => new Tool($name, null, ['type' => 'object'], null, null, meta: null === $uri
            ? null
            : ['ui' => ['resourceUri' => $uri] + ([] === $visibility ? [] : ['visibility' => $visibility])]);

        return [
            $tool('search_products'),
            $tool('open_screen', 'ui://screens', 'model', 'app'),
            $tool('screen_render', 'ui://screens', 'app'),
            $tool('screen_submit', 'ui://screens', 'app'),
            // No visibility: the specification's default is the model and the app.
            $tool('legacy_app', 'ui://legacy'),
        ];
    }
}
