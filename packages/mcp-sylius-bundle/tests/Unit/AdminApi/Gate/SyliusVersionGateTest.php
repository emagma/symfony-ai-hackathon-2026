<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Gate;

use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\Gate\RequiresApiFeature;
use Emagma\McpSyliusBundle\AdminApi\Gate\SyliusVersionGate;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyliusVersionGate::class)]
final class SyliusVersionGateTest extends TestCase
{
    public function testAToolNeedingANewerSyliusIsRefusedWithTheReasonTheModelReads(): void
    {
        $refusal = (new SyliusVersionGate(new SyliusVersion('1.14')))->refusal($this->refundTool());

        self::assertNotNull($refusal);
        self::assertFalse($refusal->answer['ok']);
        self::assertSame('sylius_version_unsupported', $refusal->answer['reason']);
        self::assertSame('payment_refund', $refusal->answer['feature']);
        self::assertIsString($refusal->answer['hint']);
        self::assertStringContainsString('Sylius 2.0 or later; this shop runs Sylius 1.14', $refusal->answer['hint']);
        self::assertSame(['feature' => 'payment_refund', 'syliusVersion' => '1.14'], $refusal->logContext);
    }

    public function testAToolPassesFromTheVersionItsFeatureAppearedIn(): void
    {
        self::assertNull((new SyliusVersionGate(new SyliusVersion('2.0')))->refusal($this->refundTool()));
        self::assertNull((new SyliusVersionGate(new SyliusVersion('2.3')))->refusal($this->refundTool()));
    }

    public function testEveryFeatureNamedMustBeAvailable(): void
    {
        $tool = $this->reference(new #[RequiresApiFeature(ApiFeature::PaymentRefund)] #[RequiresApiFeature(ApiFeature::ZonePriority)] class {
            public function __invoke(): void
            {
            }
        });

        $refusal = (new SyliusVersionGate(new SyliusVersion('2.1')))->refusal($tool);

        self::assertNotNull($refusal);
        self::assertSame('zone_priority', $refusal->answer['feature']);
        self::assertNull((new SyliusVersionGate(new SyliusVersion('2.2')))->refusal($tool));
    }

    public function testAToolThatRequiresNoFeaturePassesOnEveryLine(): void
    {
        $gate = new SyliusVersionGate(new SyliusVersion('1.14'));

        self::assertNull($gate->refusal($this->reference(new class() {
            public function __invoke(): void
            {
            }
        })));
        self::assertNull($gate->refusal(new ToolReference(new Tool('closure', null, ['type' => 'object'], null, null), static fn (): string => 'x')));
    }

    private function refundTool(): ToolReference
    {
        return $this->reference(new #[RequiresApiFeature(ApiFeature::PaymentRefund)] class {
            public function __invoke(): void
            {
            }
        });
    }

    private function reference(object $handler): ToolReference
    {
        return new ToolReference(new Tool('tool', null, ['type' => 'object'], null, null), [$handler, '__invoke']);
    }
}
