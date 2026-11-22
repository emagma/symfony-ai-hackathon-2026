<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Administrator\AdministratorSummaryOutput;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;

/**
 * The admin API declares no filter on this collection: matched here, over one bounded fetch.
 *
 * @internal
 */
#[McpTool(
    name: 'search_administrators',
    description: 'Search the Sylius BACK-OFFICE accounts (administrators), with username, email, firstName,'
        . ' lastName, localeCode, enabled and lastLogin. lastLogin is what tells a dormant account apart'
        . ' from a live one. These are staff logins, not customers - search_customers is the shop-side one.'
        . ' Filters (matched client-side, the admin API declares none on this collection): email (PARTIAL,'
        . ' case-insensitive), username (PARTIAL), enabled (true/false). Detail: get_administrators (ids).',
)]
#[Permission(resource: 'administrator', action: Action::Read)]
final readonly class SearchAdministrators
{
    use LeanLocaleHeaderTrait;

    private const int FETCH_HARD_CAP = 500;

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array{items: list<object>, total: int, page: int, hasMore: bool}
     */
    public function __invoke(
        ?string $email = null,
        ?string $username = null,
        ?bool $enabled = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);

        $payload = $this->client->getDecoded(Route::collection(Resource::Administrator), [
            'page' => 1,
            'itemsPerPage' => self::FETCH_HARD_CAP,
        ], self::leanHeaders());

        $members = AdminPayload::members($payload);

        $emailNeedle = null !== $email && '' !== $email ? mb_strtolower($email) : null;
        $usernameNeedle = null !== $username && '' !== $username ? mb_strtolower($username) : null;

        $matched = [];
        foreach ($members as $member) {
            $shaped = AdministratorSummaryOutput::fromPayload($member);

            if (null !== $enabled && $shaped->enabled !== $enabled) {
                continue;
            }
            if (null !== $emailNeedle && !self::contains($shaped->email, $emailNeedle)) {
                continue;
            }
            if (null !== $usernameNeedle && !self::contains($shaped->username, $usernameNeedle)) {
                continue;
            }

            $matched[] = $shaped;
        }

        $total = \count($matched);

        return [
            'items' => $pager->slice($matched),
            'total' => $total,
            'page' => $pager->page,
            'hasMore' => ($pager->page * $pager->itemsPerPage) < $total,
        ];
    }

    /**
     * A missing field never matches: null is unknown, not an empty string.
     */
    private static function contains(mixed $haystack, string $lowercasedNeedle): bool
    {
        return \is_string($haystack) && str_contains(mb_strtolower($haystack), $lowercasedNeedle);
    }
}
