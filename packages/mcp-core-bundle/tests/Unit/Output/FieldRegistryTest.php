<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Output;

use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Untrusted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldRegistry::class)]
#[CoversClass(Untrusted::class)]
#[CoversClass(Personal::class)]
#[CoversClass(Identifier::class)]
final class FieldRegistryTest extends TestCase
{
    public function testItReadsTheMarksOfPromotedPropertiesInDeclarationOrder(): void
    {
        self::assertSame([
            'email' => [Field::Untrusted, Field::Personal, Field::Identifier],
            'comment' => [Field::Untrusted],
            'birthday' => [Field::Personal],
        ], FieldRegistry::of($this->dto()::class));
    }

    public function testAnUnmarkedPropertyIsAbsentFromTheMap(): void
    {
        self::assertArrayNotHasKey('code', FieldRegistry::of($this->dto()::class));
    }

    public function testItListsThePropertiesThatCarryOneField(): void
    {
        $class = $this->dto()::class;

        self::assertSame(['email', 'comment'], FieldRegistry::with($class, Field::Untrusted));
        self::assertSame(['email', 'birthday'], FieldRegistry::with($class, Field::Personal));
        self::assertSame(['email'], FieldRegistry::with($class, Field::Identifier));
    }

    private function dto(): object
    {
        return new class('C-1', 'ada@example.com', 'Great.', '1815-12-10') {
            public function __construct(
                public string $code,
                #[Untrusted, Personal, Identifier]
                public ?string $email,
                #[Untrusted]
                public ?string $comment,
                #[Personal]
                public ?string $birthday,
            ) {
            }
        };
    }
}
