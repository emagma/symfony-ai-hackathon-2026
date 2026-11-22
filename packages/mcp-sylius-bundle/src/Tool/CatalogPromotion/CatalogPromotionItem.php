<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\CatalogPromotion;

use Emagma\McpSyliusBundle\Tool\Shared\PromotionConfigurationTrait;

/**
 * @internal
 */
final class CatalogPromotionItem
{
    use PromotionConfigurationTrait;

    private const array CONFIGURED = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['type' => ['type' => 'string'], 'configuration' => ['type' => 'object']],
            'required' => ['type'],
        ],
    ];

    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Immutable identifier; the item is matched on it.'],
            'locale' => ['type' => 'string', 'description' => 'The locale label and description target; defaults to the server locale.'],
            'name' => ['type' => 'string', 'description' => 'Required on a creation.'],
            'label' => ['type' => 'string', 'description' => 'Translated storefront label; a creation copies it to every enabled locale.'],
            'description' => ['type' => 'string', 'description' => 'Translated.'],
            'priority' => ['type' => 'integer'],
            'exclusive' => ['type' => 'boolean', 'description' => 'false on a creation unless given.'],
            'enabled' => ['type' => 'boolean', 'description' => 'false on a creation unless given. true PUTS THE DISCOUNT LIVE once Sylius finishes processing; false takes it down.'],
            'startDate' => ['type' => ['string', 'null'], 'description' => 'ISO-8601.'],
            'endDate' => ['type' => ['string', 'null'], 'description' => 'ISO-8601.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channel codes; REPLACES the list when given.'],
            'scopes' => self::CONFIGURED + ['description' => 'REPLACED as a whole when given ([] clears). ' . self::SCOPES_HELP],
            'actions' => self::CONFIGURED + ['description' => 'REPLACED as a whole when given ([] clears). ' . self::CATALOG_ACTIONS_HELP],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
