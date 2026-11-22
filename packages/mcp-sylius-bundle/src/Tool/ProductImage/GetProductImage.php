<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductImage\ProductImageOutput;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Reads product image one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_product_image',
    description: 'Get the detail of a single Sylius admin product image by its parent product `code`'
        . ' and numeric `id`. Returns curated fields: id, productCode, path, type.'
        . ' When the parent product or the image does not exist returns {found: false, code, id}.',
)]
#[Permission(resource: 'product_image', action: Action::Read)]
final readonly class GetProductImage
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return ProductImageOutput|array{found: false, code: string}
     */
    public function __invoke(string $code, int $id): ProductImageOutput|array
    {
        try {
            $image = $this->client->getDecoded(
                Route::item(Resource::ProductImage, $id, ['product' => $code]),
            );
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return ['found' => false, 'code' => $code, 'id' => $id];
            }

            throw $exception;
        }

        return ProductImageOutput::fromPayload($image, $code, $id);
    }
}
