<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxonImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\TaxonImage\TaxonImageOutput;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Reads taxon image one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_taxon_image',
    description: 'Get the detail of a single Sylius admin taxon image by its parent taxon `code`'
        . ' and numeric `id`. Returns curated fields: id, taxonCode, path, type.'
        . ' When the parent taxon or the image does not exist returns {found: false, code, id}.',
)]
#[Permission(resource: 'taxon_image', action: Action::Read)]
final readonly class GetTaxonImage
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return TaxonImageOutput|array{found: false, code: string}
     */
    public function __invoke(string $code, int $id): TaxonImageOutput|array
    {
        try {
            $image = $this->client->getDecoded(
                Route::item(Resource::TaxonImage, $id, ['taxon' => $code]),
            );
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return ['found' => false, 'code' => $code, 'id' => $id];
            }

            throw $exception;
        }

        return TaxonImageOutput::fromPayload($image, $code, $id);
    }
}
