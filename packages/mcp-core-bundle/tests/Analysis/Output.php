<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Analysis;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Surplus;
use Emagma\McpCoreBundle\Output\Untrusted;

/**
 * Not a test: it puts OutputFieldsTrait under PHPStan's analysis, which covers a trait only where it is used.
 */
final readonly class Output implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public string $code,
        #[Untrusted, Personal, Identifier]
        public ?string $email,
        #[Untrusted]
        public ?string $comment,
        #[Personal, Surplus]
        public ?string $birthday,
    ) {
    }
}
