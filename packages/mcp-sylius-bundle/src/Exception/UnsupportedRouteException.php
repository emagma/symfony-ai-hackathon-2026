<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Exception;

/**
 * A programming error: a tool whose route some lines lack carries #[RequiresApiFeature] and is never called on them.
 */
final class UnsupportedRouteException extends \LogicException
{
}
