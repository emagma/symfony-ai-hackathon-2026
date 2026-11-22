<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Screen;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpScreensBundle\ScreenInterface;
use Emagma\McpScreensBundle\ScreenResult;
use Emagma\McpSyliusBundle\Tool\Channel\GetChannels;
use Emagma\McpSyliusBundle\Tool\ProductVariant\GetProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\SearchProductVariants;
use Emagma\McpSyliusBundle\Tool\ProductVariant\UpsertProductVariants;
use Emagma\McpSyliusScreensBundle\Money;
use Mcp\Server\RequestContext;

/**
 * The prices and stocks of every variant of one product, in one channel at a time.
 *
 * Reads and writes with the tools of the Sylius bundle: what is saved here is confirmed and journaled
 * like an upsert_product_variants call, because it is one.
 *
 * @internal
 */
final readonly class ProductVariantsScreen implements ScreenInterface
{
    private const int MAX_VARIANTS = 50;

    public function __construct(
        private SearchProductVariants $search,
        private GetProductVariants $variants,
        private GetChannels $channels,
        private UpsertProductVariants $upsert,
    ) {
    }

    public function name(): string
    {
        return 'product_variants';
    }

    public function description(): string
    {
        return 'The prices and stocks of the variants of one product, editable in one go. params: {productCode}'
            . ' and optionally {channelCode} (the first channel of the product otherwise).';
    }

    public function readPermissions(): array
    {
        return [new Permission('product_variant', Action::Read, 'sylius'), new Permission('channel', Action::Read, 'sylius')];
    }

    public function writePermissions(): array
    {
        return [new Permission('product_variant', Action::Write, 'sylius')];
    }

    public function template(): string
    {
        return '@EmagmaMcpSyliusScreens/product_variants.html.twig';
    }

    public function load(array $params): array
    {
        return $this->read($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     productCode: string,
     *     channelCode: string,
     *     channelCodes: list<string>,
     *     currency: string|null,
     *     variants: list<array{code: string, name: string|null, price: string|null, tracked: bool, onHand: int|null, onHold: int|null}>,
     *     more: bool,
     * }
     */
    private function read(array $params): array
    {
        $productCode = \is_string($params['productCode'] ?? null) ? trim($params['productCode']) : '';
        if ('' === $productCode) {
            throw new ScreenException('This screen needs params.productCode.');
        }

        $listed = ($this->search)(product: $productCode, itemsPerPage: self::MAX_VARIANTS);
        $codes = array_column($listed['items'], 'code');
        if ([] === $codes) {
            throw new ScreenException(\sprintf('The product "%s" does not exist or has no variant.', $productCode));
        }
        $found = ($this->variants)($codes)['found'];

        $channelCodes = [];
        foreach ($found as $variant) {
            $channelCodes += array_fill_keys(array_keys($variant->pricing), true);
        }
        $channelCodes = array_map(strval(...), array_keys($channelCodes));
        $asked = \is_string($params['channelCode'] ?? null) ? $params['channelCode'] : null;
        $channelCode = \in_array($asked, $channelCodes, true) ? $asked : ($channelCodes[0] ?? null);
        if (null === $channelCode) {
            throw new ScreenException(\sprintf('The variants of "%s" have no price in any channel yet.', $productCode));
        }

        $currency = null;
        foreach (($this->channels)([$channelCode])['found'] as $channel) {
            $currency = $channel->baseCurrencyCode;
        }

        $rows = [];
        foreach ($found as $variant) {
            $price = $variant->pricing[$channelCode] ?? null;
            $rows[] = [
                'code' => $variant->code,
                'name' => $variant->name,
                'price' => null === $price ? null : Money::format($price, $currency),
                'tracked' => $variant->tracked,
                'onHand' => $variant->onHand,
                'onHold' => $variant->onHold,
            ];
        }

        return [
            'productCode' => $productCode,
            'channelCode' => $channelCode,
            'channelCodes' => $channelCodes,
            'currency' => $currency,
            'variants' => $rows,
            'more' => $listed['hasMore'],
        ];
    }

    public function submit(array $params, array $values, ?RequestContext $context): ScreenResult
    {
        try {
            $data = $this->read($params);
        } catch (ScreenException $exception) {
            return ScreenResult::notSaved($exception->getMessage());
        }
        $channelCode = $data['channelCode'];
        $currency = $data['currency'];

        $items = [];
        $errors = [];
        foreach ($data['variants'] as $index => $current) {
            $item = [];

            $typedPrice = $values['price_' . $index] ?? null;
            if (\is_string($typedPrice) && '' !== trim($typedPrice)) {
                $price = Money::parse($typedPrice, $currency);
                if (null === $price) {
                    $errors['price_' . $index] = \sprintf('Not an amount in %s.', $currency ?? 'this currency');
                } elseif (Money::format($price, $currency) !== $current['price']) {
                    $item['pricing'] = [$channelCode => $price];
                }
            }

            $typedStock = $values['stock_' . $index] ?? null;
            if ($current['tracked'] && \is_string($typedStock) && '' !== trim($typedStock)) {
                if (1 !== preg_match('/^\d{1,9}$/', trim($typedStock))) {
                    $errors['stock_' . $index] = 'A whole number, zero or more.';
                } elseif ((int) trim($typedStock) !== $current['onHand']) {
                    $item['onHand'] = (int) trim($typedStock);
                }
            }

            if ([] !== $item) {
                $items[] = ['code' => $current['code']] + $item;
            }
        }

        if ([] !== $errors) {
            return ScreenResult::invalid($errors);
        }
        if ([] === $items) {
            return ScreenResult::notSaved('Nothing changed: nothing was saved.');
        }

        $result = ($this->upsert)($items, 'update', false, $context);

        $failed = [];
        $updated = 0;
        foreach (\is_array($result['results'] ?? null) ? $result['results'] : [] as $code => $one) {
            if (!\is_array($one)) {
                continue;
            }
            if ('updated' === ($one['action'] ?? null)) {
                ++$updated;
            } elseif (true !== ($one['ok'] ?? false)) {
                $failed[] = $code . ' (' . (\is_string($one['reason'] ?? null) ? $one['reason'] : 'failed') . ')';
            }
        }

        if (true !== ($result['ok'] ?? false)) {
            return ScreenResult::notSaved(match ($result['reason'] ?? null) {
                'declined', 'cancelled' => 'You did not confirm: nothing was saved.',
                'elicitation_unavailable_preview_only', 'human_confirmation_required' => 'This client cannot ask you to confirm a write: nothing was saved.',
                default => 'Nothing was saved (' . (\is_string($result['reason'] ?? null) ? $result['reason'] : 'refused') . ').',
            });
        }
        if ([] !== $failed) {
            return ScreenResult::notSaved(\sprintf('%d variant(s) saved, not saved: %s.', $updated, implode(', ', $failed)));
        }

        return ScreenResult::saved(\sprintf('%d variant(s) saved.', $updated));
    }
}
