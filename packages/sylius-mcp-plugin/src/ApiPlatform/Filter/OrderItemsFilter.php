<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Psr\Log\LoggerInterface;

/**
 * Lean-gated search on the lines of an order: `itemsCount`, `unitsCount`, `containsProduct` and
 * `containsVariant`, through subqueries so that the paginator keeps counting orders.
 *
 * @internal
 */
final class OrderItemsFilter extends LeanGatedFilter
{
    private const string KEY_ITEMS_COUNT = 'itemsCount';

    private const string KEY_UNITS_COUNT = 'unitsCount';

    private const string KEY_CONTAINS_PRODUCT = 'containsProduct';

    private const string KEY_CONTAINS_VARIANT = 'containsVariant';

    /** @param class-string $orderItemClass */
    public function __construct(
        LeanRequestDetector $leanRequestDetector,
        private readonly string $orderItemClass,
        ManagerRegistry $managerRegistry,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($leanRequestDetector, $managerRegistry, $logger);
    }

    protected function filterLeanProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation,
        array $context,
    ): void {
        match ($property) {
            self::KEY_ITEMS_COUNT => $this->filterAggregate(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                'COUNT(%s.id)',
                self::KEY_ITEMS_COUNT,
            ),
            self::KEY_UNITS_COUNT => $this->filterAggregate(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                // An order with no lines must compare as 0, not as NULL.
                'COALESCE(SUM(%s.quantity), 0)',
                self::KEY_UNITS_COUNT,
            ),
            self::KEY_CONTAINS_PRODUCT => $this->filterContains(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                'product',
                self::KEY_CONTAINS_PRODUCT,
            ),
            self::KEY_CONTAINS_VARIANT => $this->filterContains(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                'variant',
                self::KEY_CONTAINS_VARIANT,
            ),
            default => null,
        };
    }

    /**
     * @param string $aggregate sprintf template taking the item alias
     */
    private function filterAggregate(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
        string $aggregate,
        string $key,
    ): void {
        if (!\is_array($value)) {
            $this->skip(sprintf('%s filter ignored: expected [gte|lte] bounds.', $key));

            return;
        }

        $rootAlias = self::rootAlias($queryBuilder);

        foreach (['gte' => '>=', 'lte' => '<='] as $bound => $operator) {
            $int = self::toInt($value[$bound] ?? null);
            if (null === $int) {
                continue;
            }

            $itemAlias = $queryNameGenerator->generateJoinAlias($key);
            $param = $queryNameGenerator->generateParameterName($key . '_' . $bound);

            $subQuery = sprintf(
                'SELECT ' . $aggregate . ' FROM %s %s WHERE %s.order = %s',
                $itemAlias,
                $this->orderItemClass,
                $itemAlias,
                $itemAlias,
                $rootAlias,
            );

            $queryBuilder
                ->andWhere(sprintf('(%s) %s :%s', $subQuery, $operator, $param))
                ->setParameter($param, $int)
            ;
        }
    }

    /**
     * @param string $relation `product` (through the variant) or `variant`
     */
    private function filterContains(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
        string $relation,
        string $key,
    ): void {
        $codes = self::stringList($value);
        if ([] === $codes) {
            $this->skip(sprintf('%s filter ignored: no usable code.', $key));

            return;
        }

        $itemAlias = $queryNameGenerator->generateJoinAlias($key);
        $variantAlias = $queryNameGenerator->generateJoinAlias($key . '_v');
        $param = $queryNameGenerator->generateParameterName($key);

        $joins = sprintf('JOIN %s.variant %s', $itemAlias, $variantAlias);
        $codeAlias = $variantAlias;

        if ('product' === $relation) {
            $productAlias = $queryNameGenerator->generateJoinAlias($key . '_p');
            $joins .= sprintf(' JOIN %s.product %s', $variantAlias, $productAlias);
            $codeAlias = $productAlias;
        }

        $subQuery = sprintf(
            'SELECT %s.id FROM %s %s %s WHERE %s.order = %s AND %s.code IN (:%s)',
            $itemAlias,
            $this->orderItemClass,
            $itemAlias,
            $joins,
            $itemAlias,
            self::rootAlias($queryBuilder),
            $codeAlias,
            $param,
        );

        $queryBuilder
            ->andWhere(sprintf('EXISTS (%s)', $subQuery))
            ->setParameter($param, $codes)
        ;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'itemsCount[gte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Minimum number of distinct lines in the order.',
            ],
            'itemsCount[lte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Maximum number of distinct lines in the order.',
            ],
            'unitsCount[gte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Minimum total quantity across all lines.',
            ],
            'unitsCount[lte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Maximum total quantity across all lines.',
            ],
            'containsProduct[]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Product codes; keeps orders containing AT LEAST ONE of them.',
            ],
            'containsVariant[]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Product variant codes; keeps orders containing AT LEAST ONE of them.',
            ],
        ];
    }
}
