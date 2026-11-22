<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Conformance;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationPolicy;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Channel\DeleteChannels;
use Emagma\McpSyliusBundle\Tool\Channel\SearchChannels;
use Emagma\McpSyliusBundle\Tool\Payment\RefundPayment;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class WriteGateReadsRealToolsTest extends McpToolTestCase
{
    public function testPermissionsAreReadOffARealTool(): void
    {
        $permissions = WriteConfirmationPolicy::permissionsOf(new RefundPayment($this->apiClient(), $this->audit()));

        self::assertCount(1, $permissions);
        self::assertSame('payment', $permissions[0]->resource);
        self::assertSame(Action::Write, $permissions[0]->action);
    }

    public function testTheDeleteAndReadToolsOfADomainAreClassifiedApart(): void
    {
        $strict = WriteConfirmationPolicy::secureDefault();

        self::assertFalse($strict->acceptsModelConfirmation(
            WriteConfirmationPolicy::permissionsOf(new DeleteChannels($this->apiClient(), $this->audit())),
        ));
        self::assertTrue($strict->acceptsModelConfirmation(
            WriteConfirmationPolicy::permissionsOf(new SearchChannels($this->apiClient(), 'en_US')),
        ));
    }
}
