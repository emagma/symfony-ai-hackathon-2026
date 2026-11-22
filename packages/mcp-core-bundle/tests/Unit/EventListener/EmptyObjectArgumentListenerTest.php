<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\EventListener;

use Emagma\McpCoreBundle\EventListener\EmptyObjectArgumentListener;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Event\RequestEvent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmptyObjectArgumentListener::class)]
final class EmptyObjectArgumentListenerTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string'],
            'optionValues' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'pricing' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'tags' => ['type' => 'array'],
            'mandatory' => ['type' => 'object'],
        ],
        'required' => ['code', 'mandatory'],
    ];

    /**
     * Reproduces the SDK bug the listener works around.
     */
    public function testTheSdkRejectsANestedEmptyObjectDecodedAsAnArray(): void
    {
        $arguments = json_decode('{"code":"raclette_default","optionValues":{},"mandatory":{"a":"b"}}', true);

        $errors = (new SchemaValidator())->validateAgainstJsonSchema($arguments, self::SCHEMA);

        self::assertSame('/optionValues', $errors[0]['pointer'] ?? null);
    }

    public function testAnEmptyObjectArgumentIsDroppedSoTheCallValidates(): void
    {
        $event = $this->event(json_decode('{"code":"raclette_default","optionValues":{},"pricing":{"FOOD_STORE":1500},"tags":[],"mandatory":{"a":"b"}}', true));

        ($this->listener())($event);

        $request = $event->getRequest();
        \assert($request instanceof CallToolRequest);
        self::assertSame(['code' => 'raclette_default', 'pricing' => ['FOOD_STORE' => 1500], 'tags' => [], 'mandatory' => ['a' => 'b']], $request->arguments, 'a list-typed [] is legitimate and kept');
        self::assertSame(7, $request->getId());
        self::assertSame([], (new SchemaValidator())->validateAgainstJsonSchema($request->arguments, self::SCHEMA));
    }

    public function testAnEmptyObjectInsideABatchItemIsDroppedToo(): void
    {
        $event = $this->event(['items' => [
            ['code' => 'a', 'optionValues' => [], 'pricing' => ['WEB' => 100]],
            ['code' => 'b', 'mandatory' => []],
        ], 'mandatory' => ['a' => 'b']], 'upsert_product_variants');

        ($this->listener())($event);

        $request = $event->getRequest();
        \assert($request instanceof CallToolRequest);
        self::assertSame([
            'items' => [['code' => 'a', 'pricing' => ['WEB' => 100]], ['code' => 'b', 'mandatory' => []]],
            'mandatory' => ['a' => 'b'],
        ], $request->arguments, 'the required item property is kept, the optional empty one goes');
    }

    public function testARequiredObjectIsNeverDropped(): void
    {
        $event = $this->event(['code' => 'x', 'mandatory' => []]);

        ($this->listener())($event);

        $request = $event->getRequest();
        \assert($request instanceof CallToolRequest);
        self::assertSame(['code' => 'x', 'mandatory' => []], $request->arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function event(array $arguments, string $tool = 'create_product_variant'): RequestEvent
    {
        return new RequestEvent((new CallToolRequest($tool, $arguments))->withId(7), $this->createStub(SessionInterface::class));
    }

    private function listener(): EmptyObjectArgumentListener
    {
        $registry = new Registry();
        $registry->registerTool(new Tool('create_product_variant', null, self::SCHEMA, null, null), static fn (): string => 'ok');
        $registry->registerTool(new Tool('upsert_product_variants', null, [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => self::SCHEMA], 'mandatory' => ['type' => 'object']],
            'required' => ['items', 'mandatory'],
        ], null, null), static fn (): string => 'ok');

        return new EmptyObjectArgumentListener($registry);
    }
}
