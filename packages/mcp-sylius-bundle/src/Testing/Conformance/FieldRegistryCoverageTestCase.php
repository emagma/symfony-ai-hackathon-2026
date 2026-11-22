<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpSyliusBundle\Output\OutputCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Every output DTO of the scanned sources follows the rules of the FieldRegistry: personal fields marked, nothing marked in vain.
 */
abstract class FieldRegistryCoverageTestCase extends TestCase
{
    abstract protected static function outputs(): OutputCatalog;

    protected static function minimumChecked(): int
    {
        return 1;
    }

    /**
     * Names the registry must know whatever the DTOs currently declare: the guard against erosion.
     *
     * @return list<string> lowercased property names
     */
    protected static function anchors(): array
    {
        return [];
    }

    public function testEveryOutputDtoFollowsTheRegistryRules(): void
    {
        $unmarked = [];
        $notSerialised = [];
        $pointless = [];
        $legacy = [];
        $marked = 0;

        $sentinels = static::outputs()->personalKeys();
        self::assertSame([], array_diff(static::anchors(), $sentinels), 'the registry lost a personal field it used to know');

        foreach (static::outputs()->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $fields = FieldRegistry::of($class);
            if ([] !== $fields) {
                ++$marked;
            }

            if ($reflection->hasConstant('FIELDS')) {
                $legacy[] = $class;
            }

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $name = $property->getName();
                if (\in_array(mb_strtolower($name), $sentinels, true) && !isset($fields[$name])) {
                    $unmarked[] = "$class::\$$name";
                }
            }

            // The trait is what renders the marks: a DTO carrying one must serialise through it.
            $rendered = [] !== FieldRegistry::with($class, Field::Untrusted) ||
                [] !== FieldRegistry::with($class, Field::Identifier) ||
                [] !== FieldRegistry::with($class, Field::Surplus);
            $usesTrait = \in_array(OutputFieldsTrait::class, class_uses($class) ?: [], true);
            if ($rendered && !($usesTrait && $reflection->implementsInterface(\JsonSerializable::class))) {
                $notSerialised[] = $class;
            }
            if (!$rendered && $usesTrait) {
                $pointless[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(static::minimumChecked(), $marked, 'registry discovery looks broken');
        self::assertSame([], $unmarked, "A property named like personal data must carry #[Personal], #[Untrusted] or #[Identifier]:\n" . implode("\n", $unmarked));
        self::assertSame([], $notSerialised, "An #[Untrusted], #[Identifier] or #[Surplus] property needs OutputFieldsTrait and JsonSerializable on:\n" . implode("\n", $notSerialised));
        self::assertSame([], $pointless, "OutputFieldsTrait without any #[Untrusted], #[Identifier] or #[Surplus] property on:\n" . implode("\n", $pointless));
        self::assertSame([], $legacy, "The FIELDS constant is gone, mark the properties instead on:\n" . implode("\n", $legacy));
    }
}
