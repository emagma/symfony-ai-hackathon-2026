<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Paging;

use Emagma\McpCoreBundle\Paging\PageRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageRequest::class)]
final class PageRequestTest extends TestCase
{
    public function testItClampsThePageAndTheSizeToTheirBounds(): void
    {
        $pager = PageRequest::of(0, 500);

        self::assertSame(1, $pager->page);
        self::assertSame(PageRequest::MAX_ITEMS_PER_PAGE, $pager->itemsPerPage);
        self::assertSame(1, PageRequest::of(-3, 0)->itemsPerPage);
    }

    public function testTheDefaultsAreTheOnesTheInstructionsPromise(): void
    {
        $pager = PageRequest::of();

        self::assertSame(1, $pager->page);
        self::assertSame(30, $pager->itemsPerPage);
    }

    public function testTheEnvelopeKeepsTheKeyOrderOfTheContract(): void
    {
        $envelope = PageRequest::of(2, 10)->envelope(['a', 'b'], 25);

        self::assertSame(['items' => ['a', 'b'], 'total' => 25, 'page' => 2, 'hasMore' => true], $envelope);
        self::assertFalse(PageRequest::of(3, 10)->envelope([], 25)['hasMore']);
    }

    public function testSliceTakesThePageOfACompleteList(): void
    {
        $all = range(1, 7);

        self::assertSame([4, 5, 6], PageRequest::of(2, 3)->slice($all));
        self::assertSame([7], PageRequest::of(3, 3)->slice($all));
        self::assertSame([], PageRequest::of(4, 3)->slice($all));
    }
}
