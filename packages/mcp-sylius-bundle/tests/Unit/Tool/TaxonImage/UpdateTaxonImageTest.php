<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxonImage;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxonImage\UpdateTaxonImage;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateTaxonImage::class)]
final class UpdateTaxonImageTest extends McpToolTestCase
{
    private const string CODE = 'category';

    private const int IMAGE_ID = 42;

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(UpdateTaxonImage::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('update_taxon_image', $attributes[0]->newInstance()->name);
    }

    public function testItPutsTheMutatedBodyAndRecordsAuditOnConfirm(): void
    {
        $current = $this->currentImagePayload('old_type');

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($current);

        $expectedBody = array_merge($current, ['type' => 'new_type']);
        $client->expects(self::once())
            ->method('put')
            ->with(
                'taxons/category/images/42',
                $expectedBody,
                [],
            )
            ->willReturn(array_merge($current, ['type' => 'new_type']));

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'update_taxon_image' === $e->getTool() &&
                'taxon_image' === $e->getTargetType() &&
                'category:42' === $e->getTargetCode(),
        ));

        $result = $this->call(
            new UpdateTaxonImage($client, $audit),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new_type',
            confirm: true,
        );

        self::assertTrue($result['updated']);
        self::assertSame(self::CODE, $result['code']);
        self::assertSame(self::IMAGE_ID, $result['id']);
        self::assertSame(['type' => ['before' => 'old_type', 'after' => 'new_type']], $result['diff']);
        self::assertArrayHasKey('before', $result);
        self::assertArrayHasKey('after', $result);
    }

    public function testItClearsTypeWhenEmptyStringIsPassed(): void
    {
        $current = $this->currentImagePayload('some_type');

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($current);
        $client->expects(self::once())
            ->method('put')
            ->with(
                'taxons/category/images/42',
                array_merge($current, ['type' => null]),
                [],
            )
            ->willReturn(array_merge($current, ['type' => null]));

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: '',
            confirm: true,
        );

        self::assertTrue($result['updated']);
        self::assertSame(['type' => ['before' => 'some_type', 'after' => null]], $result['diff']);
    }

    public function testItReturnsNoChangeRequestedWhenTypeIsNull(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: null,
        );

        self::assertFalse($result['updated']);
        self::assertSame('no_change_requested', $result['reason']);
    }

    public function testItReturnsNoChangeWhenTypeIsUnchanged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('same_type'));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'same_type',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('no_change', $result['reason']);
    }

    public function testItReturnsNotFoundOnMissingImage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new_type',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('old'));
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'type', 'message' => 'This value is too long.'],
        ]));

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new_type',
            confirm: true,
        );

        self::assertFalse($result['updated']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame('type', $result['errors'][0]['property']);
    }

    public function testWithNullContextItReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('old'));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new_type',
            context: null,
        );

        self::assertFalse($result['updated']);
        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertArrayHasKey('diff', $result);
        self::assertArrayHasKey('hint', $result);
    }

    public function testNonElicitingClientReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('old'));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new_type',
            context: $this->noElicitContext(),
        );

        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToUpdate(): void
    {
        $current = $this->currentImagePayload('old');
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($current);
        $client->expects(self::once())->method('put')->willReturn(array_merge($current, ['type' => 'new']));

        $tool = new UpdateTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                type: 'new',
                context: $context,
            ),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['updated']);
    }

    public function testElicitationDeclinedDoesNotUpdate(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('old'));
        $client->expects(self::never())->method('put');

        $tool = new UpdateTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                type: 'new',
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['updated']);
        self::assertSame('declined', $result['reason']);
    }

    public function testElicitationCancelledDoesNotUpdate(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->currentImagePayload('old'));
        $client->expects(self::never())->method('put');

        $tool = new UpdateTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                type: 'new',
                context: $context,
            ),
            ElicitAction::Cancel,
        );

        self::assertSame('cancelled', $result['reason']);
    }

    public function testANon404FailureIsAnUpstreamErrorClientExceptionsOnFetch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $result = $this->call(
            new UpdateTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            type: 'new',
            confirm: true,
        );

        self::assertSame(['ok' => false, 'reason' => 'upstream_error', 'status' => 500], array_intersect_key($result, ['ok' => 1, 'reason' => 1, 'status' => 1]));
    }

    /** @return array<string, mixed> */
    private function currentImagePayload(string $type): array
    {
        return [
            'id' => self::IMAGE_ID,
            'path' => 'ab/cd/photo.webp',
            'type' => $type,
            'owner' => '/api/v2/admin/taxons/' . self::CODE,
        ];
    }
}
