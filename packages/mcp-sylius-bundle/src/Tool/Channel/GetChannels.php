<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Channel;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Channel\ChannelOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Reads channels one by one in a single call: the ones that exist and the missing ones.
 */
#[McpTool(
    name: 'get_channels',
    description: 'Get channels by code, at most 50 per call. Returns'
        . ' {found: [...], missing: [codes]}. Discover codes with search_channels.',
)]
#[Permission(resource: 'channel', action: Action::Read)]
final readonly class GetChannels
{
    private const int MAX_CODES = 50;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return array{found: list<ChannelOutput>, missing: list<string>, reason?: string}
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
            $paths[$code] = Route::item(Resource::Channel, $code);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($codes as $code) {
            $result = $results[$code] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = ChannelOutput::fromPayload($result['body'], $code);
            } else {
                $missing[] = $code;
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }
}
