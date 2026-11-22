<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated price range of a product in one channel: `price[channel]` with `price[gte|lte]`.
 * The channel is required, since prices of different currencies cannot be compared.
 *
 * @internal
 */
final class ProductChannelPricingFilter extends LeanGatedFilter
{
    private const string KEY = 'price';

    protected function filterLeanProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation,
        array $context,
    ): void {
        if (self::KEY !== $property || !\is_array($value)) {
            return;
        }

        $channel = self::nonEmptyString($value['channel'] ?? null);
        if (null === $channel) {
            $this->skip('price filter ignored: channel code is required.');

            return;
        }

        $gte = self::toInt($value['gte'] ?? null);
        $lte = self::toInt($value['lte'] ?? null);
        if (null === $gte && null === $lte) {
            return;
        }

        $variantAlias = $queryNameGenerator->generateJoinAlias('v');
        $pricingAlias = $queryNameGenerator->generateJoinAlias('cp');
        $channelParam = $queryNameGenerator->generateParameterName('price_chan');

        // Build the composite ON clause so channel + bounds are correlated on
        // the SAME pricing row.
        $conditions = [sprintf('%s.channelCode = :%s', $pricingAlias, $channelParam)];
        $params = [$channelParam => $channel];

        if (null !== $gte) {
            $gteParam = $queryNameGenerator->generateParameterName('price_gte');
            $conditions[] = sprintf('%s.price >= :%s', $pricingAlias, $gteParam);
            $params[$gteParam] = $gte;
        }
        if (null !== $lte) {
            $lteParam = $queryNameGenerator->generateParameterName('price_lte');
            $conditions[] = sprintf('%s.price <= :%s', $pricingAlias, $lteParam);
            $params[$lteParam] = $lte;
        }

        $queryBuilder
            ->leftJoin(sprintf('%s.variants', self::rootAlias($queryBuilder)), $variantAlias)
            ->leftJoin(
                sprintf('%s.channelPricings', $variantAlias),
                $pricingAlias,
                'WITH',
                implode(' AND ', $conditions),
            )
            ->andWhere(sprintf('%s.id IS NOT NULL', $pricingAlias))
            ->distinct()
        ;

        foreach ($params as $name => $val) {
            $queryBuilder->setParameter($name, $val);
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'price[channel]' => [
                'type' => 'string',
                'required' => true,
                'description' => self::LEAN_NOTE . ' Channel code that scopes the price filter (e.g. FASHION_WEB). Required - without it the filter is a no-op to avoid cross-currency false positives.',
            ],
            'price[gte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Lower bound (inclusive) on the per-channel price in minor units of the channel currency.',
            ],
            'price[lte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Upper bound (inclusive) on the per-channel price in minor units of the channel currency.',
            ],
        ];
    }
}
