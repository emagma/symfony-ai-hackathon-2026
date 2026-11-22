<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

/**
 * What the shop runs, for the MCP server: the MCP extensions installed with the version of their
 * package, and the Sylius version, such as `{"extensions": {"sylius_cms": "1.1.6"}, "sylius": "2.3.0"}`.
 * Under `/admin`, so behind the admin API firewall. A singleton: no Doctrine, no identifier.
 */
#[ApiResource(
    shortName: 'SyliusMcpCapabilities',
    operations: [
        new Get(
            uriTemplate: '/admin/sylius-mcp/capabilities',
            uriVariables: [],
            name: self::OPERATION_NAME,
            provider: 'emagma_sylius_mcp.api.capabilities_provider',
        ),
    ],
    // `{}`, not `[]`, when no extension is declared: the empty array becomes
    // an ArrayObject, which the item normalizer normalizes a second time and
    // must keep as an object.
    normalizationContext: ['empty_array_as_object' => true, 'preserve_empty_objects' => true],
)]
final readonly class Capabilities
{
    public const string OPERATION_NAME = 'emagma_sylius_mcp_api_admin_capabilities_get';

    /**
     * @param array<string, string> $extensions extension name => installed version of its package
     * @param string|null             $sylius     the installed version of sylius/sylius, null when Composer does not know it
     */
    public function __construct(
        #[ApiProperty(identifier: false)]
        public array $extensions = [],
        public ?string $sylius = null,
    ) {
    }
}
