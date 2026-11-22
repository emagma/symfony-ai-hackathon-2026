<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Province;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Province\ProvinceDetailOutput;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Reads province one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_province',
    description: 'Get one Sylius admin province. BOTH codes are required - the province is addressed under'
        . ' its country (/admin/countries/{countryCode}/provinces/{code}).'
        . ' Returns code, name and abbreviation.'
        . ' When either code does not exist returns {found: false, countryCode, code}.',
)]
#[Permission(resource: 'province', action: Action::Read)]
final readonly class GetProvince
{
    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return ProvinceDetailOutput|array{found: false, countryCode: string, code: string}
     */
    public function __invoke(string $countryCode, string $code): ProvinceDetailOutput|array
    {
        try {
            $province = $this->client->getDecoded(Route::item(Resource::Province, $code, ['country' => $countryCode]));
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return ['found' => false, 'countryCode' => $countryCode, 'code' => $code];
            }

            throw $exception;
        }

        return ProvinceDetailOutput::fromPayload($province, $countryCode, $code);
    }
}
