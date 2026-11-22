<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Adapter;

/**
 * Sylius' own resources, where they live by default: the layout of 2.x, every sub-resource under its
 * parent. The adapter of the shop's line says where a line differs.
 */
enum Resource: string implements ApiResource
{
    case Address = 'address';
    case Administrator = 'administrator';
    case CatalogPromotion = 'catalog_promotion';
    case Channel = 'channel';
    case Country = 'country';
    case Currency = 'currency';
    case Customer = 'customer';
    case CustomerGroup = 'customer_group';
    case ExchangeRate = 'exchange_rate';
    case Locale = 'locale';
    case Order = 'order';
    case Payment = 'payment';
    case PaymentMethod = 'payment_method';
    case Product = 'product';
    case ProductAssociation = 'product_association';
    case ProductAssociationType = 'product_association_type';
    case ProductAttribute = 'product_attribute';
    case ProductImage = 'product_image';
    case ProductOption = 'product_option';
    case ProductOptionValue = 'product_option_value';
    case ProductReview = 'product_review';
    case ProductTaxon = 'product_taxon';
    case ProductVariant = 'product_variant';
    case Promotion = 'promotion';
    case PromotionCoupon = 'promotion_coupon';
    case Province = 'province';
    case Shipment = 'shipment';
    case ShippingCategory = 'shipping_category';
    case ShippingMethod = 'shipping_method';
    case TaxCategory = 'tax_category';
    case TaxRate = 'tax_rate';
    case Taxon = 'taxon';
    case TaxonImage = 'taxon_image';
    case Zone = 'zone';

    // A singleton: no item.
    case PluginCapabilities = 'plugin_capabilities';

    public function routeKey(): string
    {
        return $this->value;
    }

    public function itemTemplate(): ?string
    {
        return match ($this) {
            self::Address => 'addresses/{id}',
            self::Administrator => 'administrators/{id}',
            self::CatalogPromotion => 'catalog-promotions/{code}',
            self::Channel => 'channels/{code}',
            self::Country => 'countries/{code}',
            self::Currency => 'currencies/{code}',
            self::Customer => 'customers/{id}',
            self::CustomerGroup => 'customer-groups/{code}',
            self::ExchangeRate => 'exchange-rates/{id}',
            self::Locale => 'locales/{code}',
            self::Order => 'orders/{token}',
            self::Payment => 'payments/{id}',
            self::PaymentMethod => 'payment-methods/{code}',
            self::Product => 'products/{code}',
            self::ProductAssociation => 'product-associations/{id}',
            self::ProductAssociationType => 'product-association-types/{code}',
            self::ProductAttribute => 'product-attributes/{code}',
            self::ProductImage => 'products/{product}/images/{id}',
            self::ProductOption => 'product-options/{code}',
            self::ProductOptionValue => 'product-options/{option}/values/{code}',
            self::ProductReview => 'product-reviews/{id}',
            self::ProductTaxon => 'product-taxons/{id}',
            self::ProductVariant => 'product-variants/{code}',
            self::Promotion => 'promotions/{code}',
            self::PromotionCoupon => 'promotions/{promotion}/coupons/{code}',
            self::Province => 'countries/{country}/provinces/{code}',
            self::Shipment => 'shipments/{id}',
            self::ShippingCategory => 'shipping-categories/{code}',
            self::ShippingMethod => 'shipping-methods/{code}',
            self::TaxCategory => 'tax-categories/{code}',
            self::TaxRate => 'tax-rates/{code}',
            self::Taxon => 'taxons/{code}',
            self::TaxonImage => 'taxons/{taxon}/images/{id}',
            self::Zone => 'zones/{code}',
            self::PluginCapabilities => null,
        };
    }

    public function collectionTemplate(): ?string
    {
        return self::PluginCapabilities === $this ? 'sylius-mcp/capabilities' : null;
    }
}
