<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxonImage;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxonImage\DeleteTaxonImage;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeleteTaxonImage::class)]
final class DeleteTaxonImageTest extends McpToolTestCase
{
    private const string CODE = 'category';

    private const int IMAGE_ID = 42;

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(DeleteTaxonImage::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('delete_taxon_image', $attributes[0]->newInstance()->name);
    }

    public function testItDeletesAndRecordsBeforeStateInAuditOnConfirm(): void
    {
        $current = $this->imagePayload();

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($current);
        $client->expects(self::once())
            ->method('delete')
            ->with('taxons/category/images/42');

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'delete_taxon_image' === $e->getTool() &&
                'taxon_image' === $e->getTargetType() &&
                'category:42' === $e->getTargetCode() &&
                $current === $e->getBeforeState(),
        ));

        $result = $this->call(
            new DeleteTaxonImage($client, $audit),
            code: self::CODE,
            id: self::IMAGE_ID,
            confirm: true,
        );

        self::assertTrue($result['deleted']);
        self::assertSame(self::CODE, $result['code']);
        self::assertSame(self::IMAGE_ID, $result['id']);
        self::assertArrayHasKey('before', $result);
        self::assertSame('ab/cd/photo.webp', $result['before']['path']);
    }

    public function testItReturnsNotFoundWhenImageDoesNotExist(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('delete');

        $result = $this->call(
            new DeleteTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            confirm: true,
        );

        self::assertFalse($result['deleted']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testWithNullContextItReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->imagePayload());
        $client->expects(self::never())->method('delete');

        $result = $this->call(
            new DeleteTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            context: null,
        );

        self::assertFalse($result['deleted']);
        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertArrayHasKey('preview', $result);
        self::assertArrayHasKey('hint', $result);
    }

    public function testNonElicitingClientReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->imagePayload());
        $client->expects(self::never())->method('delete');

        $result = $this->call(
            new DeleteTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            context: $this->noElicitContext(),
        );

        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToDeletion(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->imagePayload());
        $client->expects(self::once())->method('delete');

        $tool = new DeleteTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                context: $context,
            ),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['deleted']);
    }

    public function testElicitationDeclinedDoesNotDelete(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->imagePayload());
        $client->expects(self::never())->method('delete');

        $tool = new DeleteTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['deleted']);
        self::assertSame('declined', $result['reason']);
    }

    public function testElicitationCancelledDoesNotDelete(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->imagePayload());
        $client->expects(self::never())->method('delete');

        $tool = new DeleteTaxonImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                id: self::IMAGE_ID,
                context: $context,
            ),
            ElicitAction::Cancel,
        );

        self::assertSame('cancelled', $result['reason']);
    }

    public function testANon404FailureIsAnUpstreamErrorFetchExceptions(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $result = $this->call(
            new DeleteTaxonImage($client, $this->audit()),
            code: self::CODE,
            id: self::IMAGE_ID,
            confirm: true,
        );

        self::assertSame(['ok' => false, 'reason' => 'upstream_error', 'status' => 500], array_intersect_key($result, ['ok' => 1, 'reason' => 1, 'status' => 1]));
    }

    /** @return array<string, mixed> */
    private function imagePayload(): array
    {
        return [
            'id' => self::IMAGE_ID,
            'path' => 'ab/cd/photo.webp',
            'type' => 'banner',
            'owner' => '/api/v2/admin/taxons/' . self::CODE,
        ];
    }
}
