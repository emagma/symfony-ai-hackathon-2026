<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * Rules and actions are pass-through, not validated here: the configuration shape belongs to a
 * registry a shop can extend, and Sylius answers 422 with the offending field.
 *
 * @internal
 */
trait PromotionConfigurationTrait
{
    public const string RULES_HELP =
        'Conditions that decide whether the promotion applies, as a list of {type, configuration}.'
        . ' Types and their configuration keys - CHANNEL-KEYED ones nest the keys under a channel code'
        . ' ({"WEB": {...}}) because they carry a money amount in minor units:'
        . ' item_total {amount} CHANNEL-KEYED;'
        . ' total_of_items_from_taxon {taxon, amount} CHANNEL-KEYED;'
        . ' cart_quantity {count};'
        . ' contains_product {product_code};'
        . ' customer_group {group_code};'
        . ' has_taxon {taxons: [codes]};'
        . ' nth_order {nth};'
        . ' shipping_country {country}.'
        . ' A shop may register its own types - an unknown type is refused by the API, not by this tool.';

    public const string ACTIONS_HELP =
        'What the promotion discounts, as a list of {type, configuration}.'
        . ' Types and their configuration keys - CHANNEL-KEYED ones nest the keys under a channel code'
        . ' ({"WEB": {...}}):'
        . ' order_fixed_discount {amount} CHANNEL-KEYED, amount in minor units (1000 = 10.00);'
        . ' unit_fixed_discount {amount} CHANNEL-KEYED;'
        . ' unit_percentage_discount {percentage} CHANNEL-KEYED;'
        . ' order_percentage_discount {percentage}, a ratio between 0 and 1 (0.15 = 15%);'
        . ' shipping_percentage_discount {percentage}, same ratio.'
        . ' A promotion with no action discounts nothing.';

    public const string SCOPES_HELP =
        'Which products the catalog promotion discounts, as a list of {type, configuration}.'
        . ' Types and their configuration keys:'
        . ' for_variants {variants: [variant codes]};'
        . ' for_products {products: [product codes]};'
        . ' for_taxons {taxons: [taxon codes]}.'
        . ' A catalog promotion with no scope discounts nothing.';

    public const string CATALOG_ACTIONS_HELP =
        'How much the catalog promotion takes off, as a list of {type, configuration}.'
        . ' Types and their configuration keys:'
        . ' percentage_discount {amount}, a ratio between 0 and 1 (0.25 = 25% off);'
        . ' fixed_discount {amount} CHANNEL-KEYED - nest it under a channel code'
        . ' ({"WEB": {"amount": 500}}), in minor units, because a fixed amount has no meaning'
        . ' outside a currency.';

    /**
     * An entry without a usable `type` is dropped: it can only produce an opaque 500 downstream.
     *
     * @return list<array{type: string, configuration: array<string, mixed>}>|null
     *         null when the caller did not supply the argument at all, which is
     *         how a partial update tells "leave this alone" apart from "empty it"
     */
    private static function normalizeConfigured(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $type = $entry['type'] ?? null;
            if (!\is_string($type) || '' === trim($type)) {
                continue;
            }
            // Keyed by string on purpose: the shape promised downstream is array<string, mixed>.
            $configuration = [];
            foreach (\is_array($entry['configuration'] ?? null) ? $entry['configuration'] : [] as $key => $option) {
                $configuration[(string) $key] = $option;
            }
            $out[] = ['type' => trim($type), 'configuration' => $configuration];
        }

        return $out;
    }
}
