<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductOption;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\ProductOption\ProductOptionOutput;
use Emagma\McpSyliusBundle\Output\ProductOption\ProductOptionValueOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads product options one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_product_options',
    description: 'Get product options by code, at most 50 per call, each with its values (code and label:'
        . ' the value codes a variant names). Returns {found: [...], missing: [codes]}. Discover codes with'
        . ' search_product_options.',
)]
#[Permission(resource: 'product_option', action: Action::Read)]
final readonly class GetProductOptions
{
    private const int MAX_CODES = 50;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<string> $codes
     * @param string|null  $locale translated fields are read in this locale (default from the environment)
     *
     * @return array{found: list<ProductOptionOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $codes,
        ?string $locale = null,
    ): array {
        $locale ??= $this->defaultLocale;
        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => '' !== trim($code))));
        if (\count($codes) > self::MAX_CODES) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_codes'];
        }
        if ([] === $codes) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($codes as $code) {
            $paths[$code] = Route::item(Resource::ProductOption, $code);
        }
        $results = $this->client->getDecodedMany($paths);

        // The option lists its values as IRIs: one more fan-out to read their codes.
        $valuePaths = [];
        foreach ($results as $result) {
            foreach (true === $result['ok'] ? AdminPayload::strings($result['body']['values'] ?? []) : [] as $iri) {
                $valuePaths[$iri] = $this->adapter->pathFromIri($iri);
            }
        }
        $values = [] === $valuePaths ? [] : $this->client->getDecodedMany($valuePaths);

        $found = [];
        $missing = [];
        foreach ($codes as $code) {
            $result = $results[$code] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $optionValues = [];
                foreach (AdminPayload::strings($result['body']['values'] ?? []) as $iri) {
                    $value = $values[$iri] ?? ['ok' => false];
                    if (true === $value['ok']) {
                        $optionValues[] = ProductOptionValueOutput::fromPayload($value['body'], $locale);
                    }
                }
                $found[] = ProductOptionOutput::fromPayload($result['body'], $locale, $code, $optionValues);
            } else {
                $missing[] = $code;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
