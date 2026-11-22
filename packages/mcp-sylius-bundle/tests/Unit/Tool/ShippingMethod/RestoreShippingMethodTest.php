<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingMethod;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingMethod\RestoreShippingMethod;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RestoreShippingMethod::class)]
final class RestoreShippingMethodTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string ARCHIVED_AT = '2026-09-07T10:00:00+00:00';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(RestoreShippingMethod::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('restore_shipping_method', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsNotFoundWhenMethodAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('patch');

        $result = $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'NOPE', confirm: true);

        self::assertFalse($result['restored']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItRefusesAMethodThatIsNotArchived(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::never())->method('patch');

        $result = $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['restored']);
        self::assertSame('not_archived', $result['reason']);
    }

    public function testItPatchesTheRestoreActionWithoutABody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::once())
            ->method('patch')
            // input="false": the body stays empty.
            ->with('shipping-methods/UPS/restore', [], [])
            ->willReturn($this->methodPayload());

        $result = $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertTrue($result['restored']);
        self::assertSame(['archived' => ['before' => true, 'after' => false]], $result['diff']);
        self::assertSame(self::ARCHIVED_AT, $result['before']['archivedAt']);
        self::assertNull($result['after']['archivedAt']);
    }

    public function testItEncodesTheCodeInThePath(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::once())
            ->method('patch')
            ->with('shipping-methods/UPS%2FEXPRESS/restore', [], [])
            ->willReturn($this->methodPayload());

        $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'UPS/EXPRESS', confirm: true);
    }

    public function testItRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->method('patch')->willReturn($this->methodPayload());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'restore_shipping_method' === $e->getTool() &&
                'shipping_method' === $e->getTargetType() &&
                'UPS' === $e->getTargetCode(),
        ));

        $this->call(new RestoreShippingMethod($client, $audit, self::LOCALE), 'UPS', confirm: true);
    }

    public function testItMapsConflict(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->method('patch')->willThrowException($this->clientException(409, [
            ['propertyPath' => '', 'message' => 'Cannot restore this method.'],
        ]));

        $result = $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['restored']);
        self::assertSame('conflict', $result['reason']);
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->method('patch')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'archivedAt', 'message' => 'Invalid.'],
        ]));

        $result = $this->call(new RestoreShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['restored']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame([['property' => 'archivedAt', 'message' => 'Invalid.']], $result['errors']);
    }

    public function testItReturnsPreviewOnlyWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::never())->method('patch');

        $result = $this->call(
            new RestoreShippingMethod($client, $this->audit(), self::LOCALE),
            'UPS',
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['restored']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::once())->method('patch')->willReturn($this->methodPayload());

        $tool = new RestoreShippingMethod($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('UPS', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['restored']);
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::never())->method('patch');

        $tool = new RestoreShippingMethod($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('UPS', confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['restored']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function methodPayload(?string $archivedAt = null): array
    {
        return [
            '@id' => '/api/v2/admin/shipping-methods/UPS',
            'code' => 'UPS',
            'translations' => [self::LOCALE => ['name' => 'UPS Express']],
            'enabled' => true,
            'archivedAt' => $archivedAt,
        ];
    }
}
