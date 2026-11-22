<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Exception;

/**
 * A screen that cannot be shown (unknown product, missing param). The message is shown to the user and given to the model.
 */
final class ScreenException extends \RuntimeException
{
}
