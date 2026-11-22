<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\WriteConfirmation;

use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffPreview::class)]
final class DiffPreviewTest extends TestCase
{
    public function testTargetsListsTheValueEachFieldWillHold(): void
    {
        self::assertSame(
            "Create \"X\"\n\n- name: \"Widget\"\n- enabled: true\n- count: 3",
            DiffPreview::targets('Create "X"', [
                'name' => ['before' => null, 'after' => 'Widget'],
                'enabled' => ['before' => null, 'after' => true],
                'count' => ['before' => null, 'after' => 3],
            ]),
        );
    }

    public function testTransitionsShowsWhatIsBeingReplaced(): void
    {
        self::assertSame(
            "Update \"X\"\n\n- name: \"Old\" -> \"New\"\n- enabled: false -> true",
            DiffPreview::transitions('Update "X"', [
                'name' => ['before' => 'Old', 'after' => 'New'],
                'enabled' => ['before' => false, 'after' => true],
            ]),
        );
    }

    public function testLongStringsAreTruncated(): void
    {
        $preview = DiffPreview::targets('h', ['f' => ['before' => null, 'after' => str_repeat('a', 200)]]);

        self::assertStringContainsString(str_repeat('a', 117) . '...', $preview);
    }

    public function testArrayKeysSurvive(): void
    {
        self::assertSame(
            '[type: "item_total", configuration: [WEB: [amount: 5000]]]',
            DiffPreview::format(['type' => 'item_total', 'configuration' => ['WEB' => ['amount' => 5000]]]),
        );
    }

    public function testNullAndBooleansReadAsThemselves(): void
    {
        self::assertSame('null', DiffPreview::format(null));
        self::assertSame('true', DiffPreview::format(true));
        self::assertSame('false', DiffPreview::format(false));
    }
}
