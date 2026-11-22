<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductAssociation\ProductAssociationOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads product associations one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_product_associations',
    description: 'Get product associations by numeric id, at most 50 per call: id, ownerCode, typeCode,'
        . ' associatedProductCodes. Returns {found: [...], missing: [ids]}. Discover ids with'
        . ' search_product_associations.',
)]
#[Permission(resource: 'product_association', action: Action::Read)]
final readonly class GetProductAssociations
{
    private const int MAX_IDS = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array{found: list<ProductAssociationOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $ids,
    ): array {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== trim($id))));
        if (\count($ids) > self::MAX_IDS) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_ids'];
        }
        if ([] === $ids) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($ids as $id) {
            $paths[$id] = Route::item(Resource::ProductAssociation, $id);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $result = $results[$id] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = ProductAssociationOutput::fromPayload($result['body'], $id);
            } else {
                $missing[] = $id;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
