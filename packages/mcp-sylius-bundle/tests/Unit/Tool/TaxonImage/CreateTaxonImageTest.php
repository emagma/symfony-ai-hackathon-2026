<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxonImage;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxonImage\CreateTaxonImage;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Part\DataPart;

#[CoversClass(CreateTaxonImage::class)]
final class CreateTaxonImageTest extends McpToolTestCase
{
    private const string CODE = 'my_taxon';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(CreateTaxonImage::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('create_taxon_image', $attributes[0]->newInstance()->name);
    }

    public function testItUploadsAFileAttachedToTheConversation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::once())
            ->method('postMultipart')
            ->with('taxons/my_taxon/images', self::callback(static function (array $parts): bool {
                self::assertInstanceOf(DataPart::class, $parts['file']);
                self::assertSame('fake-png-bytes', $parts['file']->getBody());
                self::assertSame('banner.png', $parts['file']->getFilename(), 'the filename argument wins over the client\'s name');

                return true;
            }), [])
            ->willReturn($this->imagePayload());

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput('fake-png-bytes')),
            code: self::CODE,
            filename: 'banner.png',
            file: ['download_url' => 'https://files.oaiusercontent.com/file-2', 'file_id' => 'file_2', 'file_name' => 'IMG_0001.png'],
            confirm: true,
        );

        self::assertTrue($result['created']);
        self::assertSame(['openai/fileParams' => ['file']], (new \ReflectionClass(CreateTaxonImage::class))->getAttributes(McpTool::class)[0]->newInstance()->meta);
    }

    public function testAFileFromElsewhereThanTheClientIsRefused(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            file: ['download_url' => 'https://shop/admin/export', 'file_id' => 'file_3'],
            confirm: true,
        );

        self::assertSame('file_url_refused', $result['reason']);
        self::assertSame('shop', $result['host']);
    }

    public function testItPostsMultipartWithTypeAndRecordsAuditOnConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);

        $client->expects(self::once())
            ->method('postMultipart')
            ->with(
                'taxons/my_taxon/images',
                self::callback(static function (array $parts): bool {
                    return isset($parts['file']) &&
                        $parts['file'] instanceof DataPart &&
                        isset($parts['type']) &&
                        'banner' === $parts['type'];
                }),
                [],
            )
            ->willReturn($this->imagePayload());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'create_taxon_image' === $e->getTool() &&
                'taxon_image' === $e->getTargetType() &&
                str_starts_with($e->getTargetCode(), self::CODE . ':'),
        ));

        $result = $this->call(
            new CreateTaxonImage($client, $audit, $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('fake-png-bytes'),
            filename: 'photo.png',
            type: 'banner',
            confirm: true,
        );

        self::assertTrue($result['created']);
        self::assertSame(self::CODE, $result['code']);
        self::assertSame(7, $result['id']);
        self::assertArrayHasKey('after', $result);
    }

    public function testItOmitsTypeFromPartsWhenTypeIsNull(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::once())
            ->method('postMultipart')
            ->with(
                'taxons/my_taxon/images',
                self::callback(static function (array $parts): bool {
                    return isset($parts['file']) &&
                        $parts['file'] instanceof DataPart &&
                        !array_key_exists('type', $parts);
                }),
                [],
            )
            ->willReturn($this->imagePayload());

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('data'),
            filename: 'img.png',
            confirm: true,
        );

        self::assertTrue($result['created']);
    }

    public function testItReturnsTaxonNotFoundWhenParentDoesNotExist(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: 'ghost_taxon',
            base64Content: base64_encode('x'),
            filename: 'x.png',
            confirm: true,
        );

        self::assertFalse($result['created']);
        self::assertSame('taxon_not_found', $result['reason']);
        self::assertSame('ghost_taxon', $result['code']);
    }

    public function testItReturnsBase64InvalidOnEmptyContent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: '',
            filename: 'empty.png',
            confirm: true,
        );

        self::assertFalse($result['created']);
        self::assertSame('file_required', $result['reason']);
        self::assertStringContainsString('as file', $result['hint']);
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->method('postMultipart')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'file', 'message' => 'File is too large.'],
        ]));

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('data'),
            filename: 'big.png',
            confirm: true,
        );

        self::assertFalse($result['created']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame('file', $result['errors'][0]['property']);
    }

    public function testWithNullContextItReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('data'),
            filename: 'img.png',
            context: null,
        );

        self::assertFalse($result['created']);
        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertArrayHasKey('preview', $result);
        self::assertArrayHasKey('hint', $result);
    }

    public function testNonElicitingClientReturnsPreviewOnly(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('data'),
            filename: 'img.png',
            context: $this->noElicitContext(),
        );

        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToUpload(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::once())->method('postMultipart')->willReturn($this->imagePayload());

        $tool = new CreateTaxonImage($client, $this->audit(), $this->fileInput());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                base64Content: base64_encode('data'),
                filename: 'img.png',
                context: $context,
            ),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['created']);
    }

    public function testElicitationDeclinedDoesNotUpload(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $tool = new CreateTaxonImage($client, $this->audit(), $this->fileInput());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                base64Content: base64_encode('data'),
                filename: 'img.png',
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['created']);
        self::assertSame('declined', $result['reason']);
    }

    public function testElicitationCancelledDoesNotUpload(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => self::CODE]);
        $client->expects(self::never())->method('postMultipart');

        $tool = new CreateTaxonImage($client, $this->audit(), $this->fileInput());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: self::CODE,
                base64Content: base64_encode('data'),
                filename: 'img.png',
                context: $context,
            ),
            ElicitAction::Cancel,
        );

        self::assertSame('cancelled', $result['reason']);
    }

    public function testANon404FailureIsAnUpstreamErrorPreCheckExceptions(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $result = $this->call(
            new CreateTaxonImage($client, $this->audit(), $this->fileInput()),
            code: self::CODE,
            base64Content: base64_encode('data'),
            filename: 'img.png',
            confirm: true,
        );

        self::assertSame(['ok' => false, 'reason' => 'upstream_error', 'status' => 500], array_intersect_key($result, ['ok' => 1, 'reason' => 1, 'status' => 1]));
    }

    /** @return array<string, mixed> */
    private function imagePayload(): array
    {
        return [
            'id' => 7,
            'path' => 'ab/cd/photo.png',
            'type' => 'banner',
            'owner' => '/api/v2/admin/taxons/' . self::CODE,
        ];
    }
}
