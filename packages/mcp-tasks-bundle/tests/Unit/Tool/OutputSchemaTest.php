<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Tool;

use Emagma\McpTasksBundle\Llm\OutputRejected;
use Emagma\McpTasksBundle\Llm\OutputSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputSchema::class)]
final class OutputSchemaTest extends TestCase
{
    public function testAnAnswerIsReadEvenInACodeFence(): void
    {
        $schema = OutputSchema::of(['name' => 'string', 'stock' => 'int', 'price' => 'number', 'on' => 'bool', 'kind' => 'enum[a,b]', 'note' => 'string?']);

        self::assertSame(
            ['name' => 'Apollo', 'stock' => 3, 'price' => 1.5, 'on' => false, 'kind' => 'b'],
            $schema->parse("```json\n{\"name\": \" Apollo \", \"stock\": 3, \"price\": 1.5, \"on\": false, \"kind\": \"b\", \"invented\": 1, \"note\": null}\n```"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'not json' => ['Here it is: name'];
        yield 'a missing field' => ['{"name": "a"}'];
        yield 'a wrong type' => ['{"name": 3, "kind": "a"}'];
        yield 'a value outside the enum' => ['{"name": "a", "kind": "c"}'];
        yield 'an empty string' => ['{"name": " ", "kind": "a"}'];
    }

    #[DataProvider('rejected')]
    public function testAnAnswerOutsideTheSchemaIsRejected(string $answer): void
    {
        $this->expectException(OutputRejected::class);

        OutputSchema::of(['name' => 'string', 'kind' => 'enum[a,b]'])->parse($answer);
    }

    public function testASchemaIsAMapOfKnownTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OutputSchema::of(['name' => 'text']);
    }
}
