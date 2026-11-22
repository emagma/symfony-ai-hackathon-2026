<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpSyliusBundle\Output\OutputCatalog;
use Emagma\McpSyliusBundle\SyliusSources;
use Emagma\McpSyliusBundle\Testing\Conformance\FieldRegistryCoverageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class FieldRegistryCoverageTest extends FieldRegistryCoverageTestCase
{
    protected static function outputs(): OutputCatalog
    {
        return SyliusSources::outputs();
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
