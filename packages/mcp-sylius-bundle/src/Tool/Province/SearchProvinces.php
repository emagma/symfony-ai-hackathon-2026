<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Province;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The admin API declares no flat `/admin/provinces`, only `/admin/countries/{code}/provinces`.
 *
 * @internal
 */
#[McpTool(
    name: 'search_provinces',
    description: 'Search the provinces of ONE Sylius admin country (code, name, abbreviation). The country code is'
        . ' REQUIRED - provinces are only exposed under their country, there is no shop-wide province'
        . ' collection. Returns found: false when the country does not exist - that is NOT the same as a'
        . ' country with no provinces. Detail: get_province.',
)]
#[Permission(resource: 'province', action: Action::Read)]
final readonly class SearchProvinces
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $countryCode, int $page = 1, int $itemsPerPage = 30): array
    {
        $pager = PageRequest::of($page, $itemsPerPage);

        try {
            $payload = $this->client->getDecoded(Route::collection(Resource::Province, ['country' => $countryCode]));
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return [
                    'found' => false,
                    'countryCode' => $countryCode,
                    'items' => [],
                    'total' => 0,
                    'page' => $pager->page,
                    'hasMore' => false,
                ];
            }

            throw $exception;
        }

        $members = AdminPayload::members($payload);

        $shaped = [];
        foreach ($members as $member) {
            $shaped[] = [
                'code' => AdminPayload::text($member['code']),
                'name' => \is_string($member['name'] ?? null) ? $member['name'] : null,
                'abbreviation' => \is_string($member['abbreviation'] ?? null) ? $member['abbreviation'] : null,
            ];
        }

        // A country's provinces are a bounded set: no paging of the sub-resource.
        $total = \count($shaped);

        return [
            'found' => true,
            'items' => $pager->slice($shaped),
            'total' => $total,
            'page' => $pager->page,
            'hasMore' => ($pager->page * $pager->itemsPerPage) < $total,
        ];
    }
}
