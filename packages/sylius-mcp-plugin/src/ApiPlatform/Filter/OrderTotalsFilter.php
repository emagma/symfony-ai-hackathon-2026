<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated bounds on `itemsTotal` and `adjustmentsTotal` of an order. `taxTotal` and
 * `shippingTotal` are sums over adjustments, not columns: they are not offered.
 *
 * @internal
 */
final class OrderTotalsFilter extends LeanGatedFilter
{
    /** @var list<string> */
    private const FIELDS = ['itemsTotal', 'adjustmentsTotal'];

    protected function filterLeanProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation,
        array $context,
    ): void {
        if (!\in_array($property, self::FIELDS, true)) {
            return;
        }
        if (!\is_array($value)) {
            $this->skip(sprintf('%s filter ignored: expected [gte|lte] bounds.', $property));

            return;
        }

        $rootAlias = self::rootAlias($queryBuilder);

        foreach (['gte' => '>=', 'lte' => '<='] as $bound => $operator) {
            $int = self::toInt($value[$bound] ?? null);
            if (null === $int) {
                continue;
            }

            $param = $queryNameGenerator->generateParameterName($property . '_' . $bound);
            $queryBuilder
                ->andWhere(sprintf('%s.%s %s :%s', $rootAlias, $property, $operator, $param))
                ->setParameter($param, $int)
            ;
        }
    }

    public function getDescription(string $resourceClass): array
    {
        $currency = ' Minor units. Orders of different currencies share this collection, so pair it with currencyCode or channel.code.';
        // taxTotal / shippingTotal are absent on purpose - see the class docblock.

        $description = [];
        foreach (self::FIELDS as $field) {
            foreach (['gte' => 'Lower', 'lte' => 'Upper'] as $bound => $label) {
                $description[sprintf('%s[%s]', $field, $bound)] = [
                    'type' => 'integer',
                    'required' => false,
                    'description' => sprintf('%s %s bound (inclusive) on %s.%s', self::LEAN_NOTE, $label, $field, $currency),
                ];
            }
        }

        return $description;
    }
}
