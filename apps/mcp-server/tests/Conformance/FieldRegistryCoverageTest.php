<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\Testing\Conformance\FieldRegistryCoverageTestCase;
use Emagma\SyliusMcpServer\ServerSources;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class FieldRegistryCoverageTest extends FieldRegistryCoverageTestCase
{
    protected static function outputs(): OutputCatalog
    {
        return ServerSources::outputs();
    }

    protected static function minimumChecked(): int
    {
        return 8;
    }

    protected static function anchors(): array
    {
        return ['email', 'firstname', 'lastname', 'phonenumber', 'street'];
    }
}
