<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Lists the product images, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_product_images',
    description: 'Search images attached to a Sylius admin product (id + path + type). Requires the product `code`.'
        . ' Filters (client-side): `type` (exact, nullable - Sylius uses null when no type is assigned ;'
        . ' pass an empty string to filter on null). When the parent product does not exist returns {found:'
        . ' false, code: <input>}. Detail: get_product_image.',
)]
#[Permission(resource: 'product_image', action: Action::Read)]
final readonly class SearchProductImages
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        ?string $type = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        try {
            $payload = $this->client->getDecoded(Route::collection(Resource::ProductImage, ['product' => $code]), [
                'page' => 1,
                'itemsPerPage' => self::FETCH_HARD_CAP,
            ], self::leanHeaders());
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return ['found' => false, 'code' => $code];
            }

            throw $exception;
        }
        $members = AdminPayload::members($payload);

        $matched = [];
        foreach ($members as $member) {
            $itemType = \is_string($member['type'] ?? null) ? $member['type'] : null;
            // Empty-string filter targets the null case (no type assigned).
            if (null !== $type) {
                if ('' === $type && null !== $itemType) {
                    continue;
                }
                if ('' !== $type && $itemType !== $type) {
                    continue;
                }
            }
            $matched[] = [
                'id' => \is_int($member['id'] ?? null) ? $member['id'] : 0,
                'path' => \is_string($member['path'] ?? null) ? $member['path'] : null,
                'type' => $itemType,
            ];
        }

        $total = \count($matched);

        return [
            'found' => true,
            'code' => $code,
            'items' => $pager->slice($matched),
            'total' => $total,
            'page' => $pager->page,
            'hasMore' => ($pager->page * $pager->itemsPerPage) < $total,
        ];
    }
}
