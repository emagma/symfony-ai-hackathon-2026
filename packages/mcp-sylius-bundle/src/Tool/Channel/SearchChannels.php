<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Channel;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * Lists the channels, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_channels',
    description: 'Search channels (code + name + enabled). Filters (all optional, client-side): code'
        . ' (partial, case-insensitive), name (partial, case-insensitive), enabled (true/false). Detail: get_channels.',
)]
#[Permission(resource: 'channel', action: Action::Read)]
final readonly class SearchChannels
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null, enabled: bool}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $code = null,
        ?string $name = null,
        ?bool $enabled = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $payload = $this->client->getDecoded(Route::collection(Resource::Channel), [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ], self::leanHeaders());
        $members = AdminPayload::members($payload);

        $matched = [];
        $codeNeedle = null !== $code && '' !== $code ? mb_strtolower($code) : null;
        $nameNeedle = null !== $name && '' !== $name ? mb_strtolower($name) : null;
        foreach ($members as $member) {
            $itemCode = AdminPayload::text($member['code']);
            $itemEnabled = (bool) ($member['enabled'] ?? false);
            if (null !== $enabled && $itemEnabled !== $enabled) {
                continue;
            }
            if (null !== $codeNeedle && !str_contains(mb_strtolower($itemCode), $codeNeedle)) {
                continue;
            }
            $itemName = \is_string($member['name'] ?? null) ? $member['name'] : null;
            if (null !== $nameNeedle && (null === $itemName || !str_contains(mb_strtolower($itemName), $nameNeedle))) {
                continue;
            }
            $matched[] = ['code' => $itemCode, 'name' => $itemName, 'enabled' => $itemEnabled];
        }

        $total = \count($matched);

        return $pager->envelope($pager->slice($matched), $total);
    }
}
