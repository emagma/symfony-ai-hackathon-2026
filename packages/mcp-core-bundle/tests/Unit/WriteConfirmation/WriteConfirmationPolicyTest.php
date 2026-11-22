<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\WriteConfirmation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteConfirmationPolicy::class)]
final class WriteConfirmationPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        WriteConfirmationPolicy::reset();
    }

    public function testAnUnconfiguredContainerGetsTheStrictPolicy(): void
    {
        WriteConfirmationPolicy::reset();

        $policy = WriteConfirmationPolicy::current();

        self::assertFalse($policy->acceptsModelConfirmation([new Permission('order', Action::Write, 'sylius')]));
        self::assertFalse($policy->acceptsModelConfirmation([new Permission('order', Action::Delete, 'sylius')]));
    }

    public function testReadOnlyToolsAreNeverGated(): void
    {
        self::assertTrue(
            WriteConfirmationPolicy::secureDefault()
                ->acceptsModelConfirmation([new Permission('order', Action::Read, 'sylius')]),
        );
    }

    public function testOneGatedActionInTheSetIsEnoughToRefuse(): void
    {
        $policy = new WriteConfirmationPolicy([Action::Delete]);

        self::assertTrue($policy->acceptsModelConfirmation([
            new Permission('product', Action::Read, 'sylius'),
            new Permission('taxon', Action::Write, 'sylius'),
        ]));
        self::assertFalse($policy->acceptsModelConfirmation([
            new Permission('product', Action::Read, 'sylius'),
            new Permission('taxon', Action::Delete, 'sylius'),
        ]));
    }

    public function testADeploymentWithNoHumanCanRelaxIt(): void
    {
        $policy = new WriteConfirmationPolicy([]);

        self::assertTrue($policy->acceptsModelConfirmation([new Permission('order', Action::Delete, 'sylius')]));
    }

    public function testATrustedClientMayConfirmOnTheModelsWord(): void
    {
        $policy = new WriteConfirmationPolicy([Action::Write, Action::Delete], ['Anthropic/ClaudeAI']);
        $delete = [new Permission('order', Action::Delete, 'sylius')];

        self::assertTrue($policy->acceptsModelConfirmation($delete, 'Anthropic/ClaudeAI'));
        self::assertFalse($policy->acceptsModelConfirmation($delete, 'Anthropic/Toolbox'));
        self::assertFalse($policy->acceptsModelConfirmation($delete, null));
        self::assertFalse($policy->acceptsModelConfirmation($delete));
    }

    public function testTheSecureDefaultTrustsNoClient(): void
    {
        self::assertFalse(
            WriteConfirmationPolicy::secureDefault()
                ->acceptsModelConfirmation([new Permission('order', Action::Write, 'sylius')], 'Anthropic/ClaudeAI'),
        );
    }

    public function testConfigureReplacesTheCurrentPolicy(): void
    {
        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([]));

        self::assertTrue(
            WriteConfirmationPolicy::current()
                ->acceptsModelConfirmation([new Permission('order', Action::Write, 'sylius')]),
        );
    }

    public function testPermissionsAreReadOffTheToolItself(): void
    {
        $permissions = WriteConfirmationPolicy::permissionsOf(new #[Permission(resource: 'payment', action: Action::Write)] class {
        });

        self::assertCount(1, $permissions);
        self::assertSame('payment', $permissions[0]->resource);
        self::assertSame(Action::Write, $permissions[0]->action);
    }

    public function testTheDeleteAndReadToolsOfADomainAreClassifiedApart(): void
    {
        $strict = WriteConfirmationPolicy::secureDefault();

        self::assertFalse($strict->acceptsModelConfirmation(
            WriteConfirmationPolicy::permissionsOf(new #[Permission(resource: 'channel', action: Action::Delete)] class {
            }),
        ));
        self::assertTrue($strict->acceptsModelConfirmation(
            WriteConfirmationPolicy::permissionsOf(new #[Permission(resource: 'channel', action: Action::Read)] class {
            }),
        ));
    }
}
