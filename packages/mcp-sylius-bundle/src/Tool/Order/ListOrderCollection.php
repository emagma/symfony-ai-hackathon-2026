<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Order;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Paging\PageRequest;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Order\AdjustmentOutput;
use Emagma\McpSyliusBundle\Output\Order\OrderItemOutput;
use Emagma\McpSyliusBundle\Output\Payment\PaymentOutput;
use Emagma\McpSyliusBundle\Output\Shipment\ShipmentOutput;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Items are read off the order payload: Sylius embeds them, there is no admin collection.
 *
 * @internal
 */
#[McpTool(
    name: 'list_order_collection',
    description: 'List one sub-collection of a Sylius admin order, by order tokenValue (NOT number, NOT id) and'
        . ' kind: items (lines: productCode, variantCode, quantity, unitPrice, total…), payments (state,'
        . ' amount, methodCode), shipments (state, methodCode, tracking), adjustments (type, label, amount,'
        . ' neutral - promotions, shipping and taxes; use it when a total does not add up; filter: type).'
        . ' Amounts are minor units of the order currency. Returns found: false when the order does not'
        . ' exist, which is not the same as an order with an empty collection.',
)]
#[Permission(resource: 'order', action: Action::Read)]
final readonly class ListOrderCollection
{
    use OrderSubCollectionTrait;

    public const array KINDS = ['items', 'payments', 'shipments', 'adjustments'];

    public function __construct(
        private AdminApiClientInterface $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $tokenValue,
        #[Schema(enum: self::KINDS)]
        string $kind,
        ?string $type = null,
        int $page = 1,
        int $itemsPerPage = 30,
    ): array {
        $pager = PageRequest::of($page, $itemsPerPage);
        if (!\in_array($kind, self::KINDS, true)) {
            return ['found' => false, 'tokenValue' => $tokenValue, 'reason' => 'invalid_kind', 'hint' => 'kind is one of ' . implode(', ', self::KINDS) . '.'] + $pager->envelope([], 0);
        }

        $path = 'items' === $kind ? Route::item(Resource::Order, $tokenValue) : Route::action(Resource::Order, $tokenValue, $kind);
        // The adjustments operation declares a `type` parameter of its own.
        $query = 'adjustments' === $kind && null !== $type && '' !== $type ? ['type' => $type] : [];

        try {
            $payload = $this->client->getDecoded($path, $query);
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return self::orderNotFound($tokenValue, $pager);
            }

            throw $exception;
        }

        $members = 'items' === $kind
            ? $this->withProducts(\is_array($payload['items'] ?? null) ? $payload['items'] : [])
            : self::members($payload);
        $shaped = [];
        foreach ($members as $member) {
            if (!\is_array($member)) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = $member;
            $shaped[] = match ($kind) {
                'items' => OrderItemOutput::fromPayload($payload),
                'payments' => PaymentOutput::fromPayload($payload),
                'shipments' => ShipmentOutput::fromPayload($payload),
                default => AdjustmentOutput::fromPayload($payload),
            };
        }

        return ['kind' => $kind] + self::paginate($shaped, $pager);
    }

    /**
     * The order's lines name their variant only (Sylius serialises `product` in the order
     * item's own `show` group, not in the order's): the variants are read in one fan-out.
     *
     * @param array<mixed> $items
     *
     * @return array<mixed>
     */
    private function withProducts(array $items): array
    {
        $variants = [];
        foreach ($items as $item) {
            if (\is_array($item) && null === AdminPayload::code($item['product'] ?? null)) {
                $variant = AdminPayload::code($item['variant'] ?? null);
                if (null !== $variant) {
                    $variants[$variant] = Route::item(Resource::ProductVariant, $variant);
                }
            }
        }
        if ([] === $variants) {
            return $items;
        }

        $products = [];
        foreach ($this->client->getDecodedMany($variants) as $variant => $result) {
            if (true === $result['ok'] && \is_string($result['body']['product'] ?? null)) {
                $products[$variant] = $result['body']['product'];
            }
        }
        foreach ($items as $index => $item) {
            if (!\is_array($item) || isset($item['product'])) {
                continue;
            }
            $variant = AdminPayload::code($item['variant'] ?? null);
            if (null !== $variant && isset($products[$variant])) {
                $item['product'] = $products[$variant];
                $items[$index] = $item;
            }
        }

        return $items;
    }
}
