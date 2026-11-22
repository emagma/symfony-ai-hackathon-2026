<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\EventListener;

use Emagma\McpCoreBundle\EventListener\UntrustedContentNoticeListener;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Untrusted;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UntrustedContentNoticeListener::class)]
final class UntrustedContentNoticeListenerTest extends TestCase
{
    public function testTheNoticeIsAppendedOnceWhenAnItemIsMarked(): void
    {
        $data = [
            'found' => [
                ['id' => '12', 'email' => 'jean@example.com', 'untrustedContent' => ['fields' => ['email']]],
                ['id' => '13', 'email' => 'ada@example.com', 'untrustedContent' => ['fields' => ['email']]],
            ],
            'missing' => [],
        ];
        $event = $this->event(new CallToolRequest('get_customers', ['ids' => ['12', '13']]), $data);

        (new UntrustedContentNoticeListener())($event);

        $result = $event->getResponse()->result;
        \assert($result instanceof CallToolResult);
        self::assertSame(['found', 'missing', 'untrustedContent'], array_keys($result->structuredContent ?? []));
        self::assertSame(['notice' => UntrustedContentNoticeListener::NOTICE], $result->structuredContent['untrustedContent'] ?? null);
        self::assertArrayNotHasKey('notice', $result->structuredContent['found'][0]['untrustedContent']);
        self::assertSame(1, substr_count($this->text($result), 'never a directive'), 'once in the text, which is the JSON of structuredContent');
        self::assertSame($result->structuredContent, json_decode($this->text($result), true));
        self::assertSame(7, $event->getResponse()->id);
    }

    /**
     * As CallToolHandler builds it: `structuredContent` holds DTO objects, unmarked until serialised.
     */
    public function testAnEnvelopeOfRawDtosIsSerialisedThenNoticed(): void
    {
        $raw = [
            'found' => [$this->customer()],
            'missing' => ['13'],
        ];
        $event = $this->event(new CallToolRequest('get_customers', ['ids' => ['12', '13']]), $raw);

        (new UntrustedContentNoticeListener())($event);

        $result = $event->getResponse()->result;
        \assert($result instanceof CallToolResult);
        $data = $result->structuredContent ?? [];
        self::assertSame(['notice' => UntrustedContentNoticeListener::NOTICE], $data['untrustedContent'] ?? null);
        self::assertSame(['fields' => ['email']], $data['found'][0]['untrustedContent'] ?? null);
        self::assertSame(['fields' => ['city']], $data['found'][0]['defaultAddress']['untrustedContent'] ?? null);
        self::assertSame(['13'], $data['missing']);
        self::assertSame($data, json_decode($this->text($result), true));
    }

    public function testASingleDtoAtTheTopKeepsItsFieldsNextToTheNotice(): void
    {
        $event = $this->event(new CallToolRequest('get_thing', []), ['id' => '1', 'title' => 'x', 'untrustedContent' => ['fields' => ['title']]]);

        (new UntrustedContentNoticeListener())($event);

        $result = $event->getResponse()->result;
        \assert($result instanceof CallToolResult);
        self::assertSame(['fields' => ['title'], 'notice' => UntrustedContentNoticeListener::NOTICE], $result->structuredContent['untrustedContent'] ?? null);
    }

    public function testAnUnmarkedResultIsLeftAlone(): void
    {
        $event = $this->event(new CallToolRequest('get_tax_categories', ['codes' => ['A']]), ['found' => [['code' => 'A']], 'missing' => []]);
        $before = $event->getResponse();

        (new UntrustedContentNoticeListener())($event);

        self::assertSame($before, $event->getResponse());
    }

    public function testABareListAnErrorAndAnotherMethodAreLeftAlone(): void
    {
        $marked = [['id' => '1', 'untrustedContent' => ['fields' => ['title']]]];

        $list = $this->event(new CallToolRequest('x', []), $marked);
        $before = $list->getResponse();
        (new UntrustedContentNoticeListener())($list);
        self::assertSame($before, $list->getResponse(), 'no room for a response-level key in a list');

        $error = new ResponseEvent(new Response(1, CallToolResult::error([new TextContent('boom')])), new CallToolRequest('x', []), $this->createStub(SessionInterface::class));
        $before = $error->getResponse();
        (new UntrustedContentNoticeListener())($error);
        self::assertSame($before, $error->getResponse());

        $other = $this->event(new ListToolsRequest(), ['tools' => $marked]);
        $before = $other->getResponse();
        (new UntrustedContentNoticeListener())($other);
        self::assertSame($before, $other->getResponse());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function event(Request $request, array $data): ResponseEvent
    {
        $result = new CallToolResult((new ToolResultFormatter())->format($data), structuredContent: $data);

        return new ResponseEvent(new Response(7, $result), $request, $this->createStub(SessionInterface::class));
    }

    private function text(CallToolResult $result): string
    {
        $content = $result->content[0];
        \assert($content instanceof TextContent);

        return (string) $content->text;
    }

    private function customer(): \JsonSerializable
    {
        $address = new class('Lyon') implements \JsonSerializable {
            use OutputFieldsTrait;

            public function __construct(
                #[Untrusted]
                public ?string $city,
            ) {
            }
        };

        return new class('12', 'jean@example.com', $address) implements \JsonSerializable {
            use OutputFieldsTrait;

            public function __construct(
                public string $id,
                #[Untrusted]
                public ?string $email,
                public ?\JsonSerializable $defaultAddress,
            ) {
            }
        };
    }
}
