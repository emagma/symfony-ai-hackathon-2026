<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductImage;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Output\ProductImage\ProductImageOutput;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductImage\CreateProductImage;
use Emagma\McpSyliusBundle\Tool\ProductImage\DeleteProductImage;
use Emagma\McpSyliusBundle\Tool\ProductImage\GetProductImage;
use Emagma\McpSyliusBundle\Tool\ProductImage\SearchProductImages;
use Emagma\McpSyliusBundle\Tool\ProductImage\UpdateProductImage;
use Emagma\McpSyliusBundle\Tool\Shared\Base64Content;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Part\DataPart;

#[CoversClass(SearchProductImages::class)]
#[CoversClass(GetProductImage::class)]
#[CoversClass(CreateProductImage::class)]
#[CoversClass(UpdateProductImage::class)]
#[CoversClass(DeleteProductImage::class)]
#[CoversClass(ProductImageShaper::class)]
final class ProductImageToolsTest extends McpToolTestCase
{
    private const string PATH = 'products/MUG/images/3';

    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            SearchProductImages::class => 'search_product_images',
            GetProductImage::class => 'get_product_image',
            CreateProductImage::class => 'create_product_image',
            UpdateProductImage::class => 'update_product_image',
            DeleteProductImage::class => 'delete_product_image',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    public function testItExposesTheVariantScope(): void
    {
        $shaped = ProductImageOutput::fromPayload($this->image(), 'MUG', 3);

        self::assertSame(['MUG_BLUE'], $shaped->variantCodes);
        self::assertSame(2, $shaped->position);
        self::assertSame('MUG', $shaped->productCode);
    }

    public function testGetReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new GetProductImage($client), 'MUG', 99);

        self::assertFalse($result['found']);
    }

    public function testCreateRejectsInvalidBase64BeforeAnyUpload(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => 'MUG']);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(
            new CreateProductImage($client, $this->audit(), $this->fileInput()),
            'MUG',
            '!!! not base64 !!!',
            'mug.png',
            confirm: true,
        );

        self::assertFalse($result['created']);
        self::assertSame('base64_invalid', $result['reason']);
        self::assertSame(Base64Content::INVALID_HINT, $result['hint']);
    }

    public function testCreateReportsAMissingProduct(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(new CreateProductImage($client, $this->audit(), $this->fileInput()), 'NOPE', self::PIXEL, 'mug.png', confirm: true);

        self::assertSame('product_not_found', $result['reason']);
    }

    public function testCreateUploadsTheDecodedFileAsMultipart(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => 'MUG']);
        $client->expects(self::once())
            ->method('postMultipart')
            ->with('products/MUG/images', self::callback(static function (array $parts): bool {
                self::assertInstanceOf(DataPart::class, $parts['file']);
                self::assertSame('main', $parts['type']);

                return true;
            }), [])
            ->willReturn($this->image());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'create_product_image' === $e->getTool() &&
                'product_image' === $e->getTargetType(),
        ));

        $result = $this->call(
            new CreateProductImage($client, $audit, $this->fileInput()),
            'MUG',
            self::PIXEL,
            'mug.png',
            type: 'main',
            confirm: true,
        );

        self::assertTrue($result['created']);
        self::assertSame(3, $result['id']);
    }

    public function testCreateUploadsAFileAttachedToTheConversation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => 'MUG']);
        $client->expects(self::once())
            ->method('postMultipart')
            ->with('products/MUG/images', self::callback(static function (array $parts): bool {
                self::assertInstanceOf(DataPart::class, $parts['file']);
                self::assertSame((string) base64_decode(self::PIXEL, true), $parts['file']->getBody());
                self::assertSame('mug.png', $parts['file']->getFilename());
                self::assertSame('image/png', $parts['file']->getContentType(), 'sniffed from the bytes');

                return true;
            }), [])
            ->willReturn($this->image());
        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(static function (RecordedWrite $e): bool {
            self::assertSame(['before' => null, 'after' => 'file_1'], $e->getDiff()['fileId']);
            self::assertStringNotContainsString('sig=secret', (string) json_encode($e->getDiff()), 'the bearer URL is in no record');

            return true;
        }));

        $result = $this->call(
            new CreateProductImage($client, $audit, $this->fileInput((string) base64_decode(self::PIXEL, true))),
            'MUG',
            file: ['download_url' => 'https://files.oaiusercontent.com/file-1?sig=secret', 'file_id' => 'file_1', 'file_name' => 'mug.png'],
            confirm: true,
        );

        self::assertTrue($result['created']);
    }

    public function testCreateNeedsTheFileInOneOfItsTwoForms(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => 'MUG']);
        $client->expects(self::never())->method('postMultipart');

        $result = $this->call(new CreateProductImage($client, $this->audit(), $this->fileInput()), 'MUG', confirm: true);

        self::assertSame(['ok' => false, 'created' => false, 'code' => 'MUG', 'reason' => 'file_required'], array_diff_key($result, ['hint' => true]));
    }

    public function testTheUploadToolsDeclareTheFileArgumentToChatGpt(): void
    {
        $attribute = (new \ReflectionClass(CreateProductImage::class))->getAttributes(McpTool::class)[0]->newInstance();

        self::assertSame(['openai/fileParams' => ['file']], $attribute->meta);
        self::assertStringContainsString('file_required', (string) $attribute->description);
    }

    public function testUpdateShortCircuitsWithoutReachingTheNetwork(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $result = $this->call(new UpdateProductImage($client, $this->audit()), 'MUG', 3, confirm: true);

        self::assertSame('no_change_requested', $result['reason']);
    }

    public function testUpdateChangesTypeAndPositionTogether(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, self::callback(static function (array $body): bool {
                self::assertSame('thumbnail', $body['type']);
                self::assertSame(5, $body['position']);
                // Untouched keys travel back: the admin PUT is not partial.
                self::assertSame('a/b/mug.png', $body['path']);

                return true;
            }), [])
            ->willReturn($this->image());

        $result = $this->call(
            new UpdateProductImage($client, $this->audit()),
            'MUG',
            3,
            type: 'thumbnail',
            position: 5,
            confirm: true,
        );

        self::assertTrue($result['updated']);
        self::assertSame(['type', 'position'], array_keys($result['diff']));
    }

    public function testUpdateResolvesVariantCodesToIris(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->method('getDecodedMany')->willReturn([
            'MUG_RED' => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/product-variants/MUG_RED']],
        ]);
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, self::callback(static function (array $body): bool {
                self::assertSame(['/api/v2/admin/product-variants/MUG_RED'], $body['productVariants']);

                return true;
            }), [])
            ->willReturn($this->image());

        $result = $this->call(new UpdateProductImage($client, $this->audit()), 'MUG', 3, variantCodes: ['MUG_RED'], confirm: true);

        self::assertTrue($result['updated']);
        self::assertSame(
            ['variantCodes' => ['before' => ['MUG_BLUE'], 'after' => ['MUG_RED']]],
            $result['diff'],
        );
    }

    public function testUpdateNamesUnknownVariants(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->method('getDecodedMany')->willReturn([
            'GHOST' => ['ok' => false, 'status' => 404, 'error' => 'not found'],
        ]);
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateProductImage($client, $this->audit()), 'MUG', 3, variantCodes: ['GHOST'], confirm: true);

        self::assertSame('variants_not_found', $result['reason']);
        self::assertSame(['GHOST'], $result['missing']);
    }

    public function testUpdateDetachesTheImageFromEveryVariant(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::never())->method('getDecodedMany');
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, self::callback(static function (array $body): bool {
                // An empty list makes the image apply to the whole product.
                self::assertSame([], $body['productVariants']);

                return true;
            }), [])
            ->willReturn($this->image());

        $this->call(new UpdateProductImage($client, $this->audit()), 'MUG', 3, variantCodes: [], confirm: true);
    }

    public function testUpdateReturnsNoChangeWhenNothingDiffers(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new UpdateProductImage($client, $this->audit()),
            'MUG',
            3,
            type: 'main',
            position: 2,
            variantCodes: ['MUG_BLUE'],
            confirm: true,
        );

        self::assertSame('no_change', $result['reason']);
    }

    public function testDeleteRemovesTheImage(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::once())->method('delete')->with(self::PATH);

        $result = $this->call(new DeleteProductImage($client, $this->audit()), 'MUG', 3, confirm: true);

        self::assertTrue($result['deleted']);
    }

    public function testTheWriteToolsRefuseWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::never())->method('put');
        $client->expects(self::never())->method('delete');

        $context = $this->noElicitContext();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new UpdateProductImage($client, $this->audit()), 'MUG', 3, type: 'x', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new DeleteProductImage($client, $this->audit()), 'MUG', 3, context: $context)['reason'],
        );
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->image());
        $client->expects(self::never())->method('delete');

        $tool = new DeleteProductImage($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('MUG', 3, confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['deleted']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function image(): array
    {
        return [
            '@id' => '/api/v2/admin/products/MUG/images/3',
            'id' => 3,
            'owner' => '/api/v2/admin/products/MUG',
            'path' => 'a/b/mug.png',
            'type' => 'main',
            'position' => 2,
            'productVariants' => ['/api/v2/admin/product-variants/MUG_BLUE'],
        ];
    }
}
