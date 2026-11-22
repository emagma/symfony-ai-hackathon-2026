<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingMethod;

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
 * Lists the shipping methods, filtered and paged.
 *
 * @internal
 */
#[McpTool(
    name: 'search_shipping_methods',
    description: 'Search shipping methods (code + translated name + enabled). Filters (all optional,'
        . ' client-side): code (partial, case-insensitive), name (partial, translated, case-insensitive),'
        . ' enabled (true/false), channel (exact channel code - shipping method must include this'
        . ' channel), zone (exact zone code), archived (omit for active methods only - the default; true'
        . ' lists ONLY archived ones, false is the explicit form of the default). Detail: get_shipping_methods.',
)]
#[Permission(resource: 'shipping_method', action: Action::Read)]
final readonly class SearchShippingMethods
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
        private string $defaultLocale,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{code: string, name: string|null, enabled: bool, archived: bool}>,
     *     total: int,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function __invoke(
        ?string $code = null,
        ?string $name = null,
        ?bool $enabled = null,
        ?string $channel = null,
        ?string $zone = null,
        ?bool $archived = null,
        int $page = 1,
        int $itemsPerPage = 30,
        ?string $locale = null,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        $locale ??= $this->defaultLocale;

        $query = [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ];
        if (null !== $archived) {
            // Sylius hides archived methods from every collection unless `exists[archivedAt]` is present.
            $query['exists'] = ['archivedAt' => $archived ? 'true' : 'false'];
        }

        $payload = $this->client->getDecoded(Route::collection(Resource::ShippingMethod), $query, self::leanLocaleHeaders($locale));
        $members = AdminPayload::members($payload);

        $matched = [];
        $codeNeedle = null !== $code && '' !== $code ? mb_strtolower($code) : null;
        $nameNeedle = null !== $name && '' !== $name ? mb_strtolower($name) : null;
        foreach ($members as $member) {
            $itemCode = \is_string($member['code'] ?? null) ? $member['code'] : '';
            $itemEnabled = (bool) ($member['enabled'] ?? false);
            if (null !== $enabled && $itemEnabled !== $enabled) {
                continue;
            }
            if (null !== $codeNeedle && !str_contains(mb_strtolower($itemCode), $codeNeedle)) {
                continue;
            }
            if (null !== $zone && AdminPayload::code($member['zone'] ?? null) !== $zone) {
                continue;
            }
            if (null !== $channel) {
                $channelCodes = AdminPayload::codes($member['channels'] ?? []);
                if (!\in_array($channel, $channelCodes, true)) {
                    continue;
                }
            }
            $itemName = AdminPayload::translation($member['translations'] ?? [], $locale, 'name');
            if (null !== $nameNeedle && (null === $itemName || !str_contains(mb_strtolower($itemName), $nameNeedle))) {
                continue;
            }
            $matched[] = [
                'code' => $itemCode,
                'name' => $itemName,
                'enabled' => $itemEnabled,
                'archived' => null !== ($member['archivedAt'] ?? null),
            ];
        }

        $total = \count($matched);

        return $pager->envelope($pager->slice($matched), $total);
    }
}
