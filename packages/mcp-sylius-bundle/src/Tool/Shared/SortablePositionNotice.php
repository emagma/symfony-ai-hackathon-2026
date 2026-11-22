<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * These fields are `<gedmo:sortable-position/>` in Sylius' mapping: Gedmo clamps an out-of-range
 * value and renumbers the siblings, and the API answers 200 either way.
 *
 * @internal
 */
final class SortablePositionNotice
{
    public const string POSITION =
        ' position is a Gedmo Sortable position, not a free integer: an out-of-range value is clamped,'
        . ' the stored value can differ from the one sent, and the siblings are renumbered outside this'
        . ' diff. Read the list back.';

    public const string PRIORITY =
        ' priority is a Gedmo Sortable position, not a free integer: an out-of-range value is clamped,'
        . ' the stored value can differ from the one sent, and the other promotions are renumbered outside'
        . ' this diff. Read the list back.';

    private function __construct()
    {
    }
}
