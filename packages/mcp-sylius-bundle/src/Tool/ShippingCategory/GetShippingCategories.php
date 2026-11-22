<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingCategory;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ShippingCategory\ShippingCategoryOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads shipping categories one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_shipping_categories',
    description: 'Get shipping categories by code, at most 50 per call. Returns'
        . ' {found: [...], missing: [codes]}. Discover codes with search_shipping_categories.',
)]
#[Permission(resource: 'shipping_category', action: Action::Read)]
final readonly class GetShippingCategories
{
    private const int MAX_CODES = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return array{found: list<ShippingCategoryOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $codes,
    ): array {
        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => '' !== trim($code))));
        if (\count($codes) > self::MAX_CODES) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_codes'];
        }
        if ([] === $codes) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($codes as $code) {
            $paths[$code] = Route::item(Resource::ShippingCategory, $code);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($codes as $code) {
            $result = $results[$code] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = ShippingCategoryOutput::fromPayload($result['body'], $code);
            } else {
                $missing[] = $code;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
