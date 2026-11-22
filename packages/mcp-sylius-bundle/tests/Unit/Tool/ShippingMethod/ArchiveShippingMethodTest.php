<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ShippingMethod;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ShippingMethod\ArchiveShippingMethod;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArchiveShippingMethod::class)]
final class ArchiveShippingMethodTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string ARCHIVED_AT = '2026-09-07T10:00:00+00:00';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(ArchiveShippingMethod::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('archive_shipping_method', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsNotFoundWhenMethodAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('patch');

        $result = $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'NOPE', confirm: true);

        self::assertFalse($result['archived']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItRefusesAnAlreadyArchivedMethod(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload(self::ARCHIVED_AT));
        $client->expects(self::never())->method('patch');

        $result = $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['archived']);
        self::assertSame('already_archived', $result['reason']);
        self::assertSame(self::ARCHIVED_AT, $result['archivedAt']);
    }

    public function testItPatchesTheArchiveActionWithoutABody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::once())
            ->method('patch')
            // input="false": the body stays empty.
            ->with('shipping-methods/UPS/archive', [], [])
            ->willReturn($this->methodPayload(self::ARCHIVED_AT));

        $result = $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertTrue($result['archived']);
        self::assertSame(['archived' => ['before' => false, 'after' => true]], $result['diff']);
        self::assertNull($result['before']['archivedAt']);
        self::assertSame(self::ARCHIVED_AT, $result['after']['archivedAt']);
    }

    public function testItEncodesTheCodeInThePath(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::once())
            ->method('patch')
            ->with('shipping-methods/UPS%2FEXPRESS/archive', [], [])
            ->willReturn($this->methodPayload(self::ARCHIVED_AT));

        $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'UPS/EXPRESS', confirm: true);
    }

    public function testItRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->method('patch')->willReturn($this->methodPayload(self::ARCHIVED_AT));

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'archive_shipping_method' === $e->getTool() &&
                'shipping_method' === $e->getTargetType() &&
                'UPS' === $e->getTargetCode(),
        ));

        $this->call(new ArchiveShippingMethod($client, $audit, self::LOCALE), 'UPS', confirm: true);
    }

    public function testItMapsConflict(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->method('patch')->willThrowException($this->clientException(409, [
            ['propertyPath' => '', 'message' => 'Cannot archive a method in use.'],
        ]));

        $result = $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['archived']);
        self::assertSame('conflict', $result['reason']);
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->method('patch')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'archivedAt', 'message' => 'Invalid.'],
        ]));

        $result = $this->call(new ArchiveShippingMethod($client, $this->audit(), self::LOCALE), 'UPS', confirm: true);

        self::assertFalse($result['archived']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame([['property' => 'archivedAt', 'message' => 'Invalid.']], $result['errors']);
    }

    public function testItReturnsPreviewOnlyWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::never())->method('patch');

        $result = $this->call(
            new ArchiveShippingMethod($client, $this->audit(), self::LOCALE),
            'UPS',
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['archived']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::once())->method('patch')->willReturn($this->methodPayload(self::ARCHIVED_AT));

        $tool = new ArchiveShippingMethod($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('UPS', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['archived']);
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->methodPayload());
        $client->expects(self::never())->method('patch');

        $tool = new ArchiveShippingMethod($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('UPS', confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['archived']);
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
