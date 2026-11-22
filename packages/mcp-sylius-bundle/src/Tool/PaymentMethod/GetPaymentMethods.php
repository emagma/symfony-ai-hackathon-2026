<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PaymentMethod;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\PaymentMethod\PaymentMethodOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads payment methods one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_payment_methods',
    description: 'Get payment methods by code, at most 50 per call. Returns'
        . ' {found: [...], missing: [codes]}. Discover codes with search_payment_methods.',
)]
#[Permission(resource: 'payment_method', action: Action::Read)]
final readonly class GetPaymentMethods
{
    private const int MAX_CODES = 50;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param list<string> $codes
     * @param string|null  $locale translated fields are read in this locale (default from the environment)
     *
     * @return array{found: list<PaymentMethodOutput>, missing: list<string>, reason?: string}
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
            $paths[$code] = Route::item(Resource::PaymentMethod, $code);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($codes as $code) {
            $result = $results[$code] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = PaymentMethodOutput::fromPayload($result['body'], $locale, $code);
            } else {
                $missing[] = $code;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
