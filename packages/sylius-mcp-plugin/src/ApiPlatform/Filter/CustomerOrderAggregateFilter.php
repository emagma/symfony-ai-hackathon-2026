<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\Model\OrderInterface;

/**
 * Lean-gated search on what a customer ordered: `ordersTotal`, `ordersCount` and `lastOrder`,
 * through correlated subqueries so that the paginator keeps counting customers. Totals need
 * `orderAggregate[channel]`; only paid orders count unless `orderAggregate[paymentStates][]` says otherwise.
 *
 * @internal
 */
final class CustomerOrderAggregateFilter extends LeanGatedFilter
{
    private const string KEY_TOTAL = 'ordersTotal';

    private const string KEY_COUNT = 'ordersCount';

    private const string KEY_LAST_ORDER = 'lastOrder';

    private const string KEY_OPTIONS = 'orderAggregate';

    /** @param class-string $orderClass */
    public function __construct(
        LeanRequestDetector $leanRequestDetector,
        private readonly string $orderClass,
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
        if (!\in_array($property, [self::KEY_TOTAL, self::KEY_COUNT, self::KEY_LAST_ORDER], true)) {
            return;
        }
        if (!\is_array($value)) {
            $this->skip(sprintf('%s filter ignored: expected bounds.', $property));

            return;
        }

        $options = $context['filters'][self::KEY_OPTIONS] ?? [];
        $channel = \is_array($options) ? self::nonEmptyString($options['channel'] ?? null) : null;
        $paymentStates = \is_array($options) ? self::paymentStates($options['paymentStates'] ?? null) : [OrderPaymentStates::STATE_PAID];

        if (self::KEY_TOTAL === $property && null === $channel) {
            $this->skip(
                'ordersTotal filter ignored: orderAggregate[channel] is required, otherwise totals of'
                . ' different currencies would be summed together.',
            );

            return;
        }

        match ($property) {
            self::KEY_TOTAL => $this->applyBounds(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                ['gte' => '>=', 'lte' => '<='],
                'COALESCE(SUM(%s.total), 0)',
                $channel,
                $paymentStates,
                self::KEY_TOTAL,
                static fn (mixed $bound): ?int => self::toInt($bound),
            ),
            self::KEY_COUNT => $this->applyBounds(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                ['gte' => '>=', 'lte' => '<='],
                'COUNT(%s.id)',
                $channel,
                $paymentStates,
                self::KEY_COUNT,
                static fn (mixed $bound): ?int => self::toInt($bound),
            ),
            self::KEY_LAST_ORDER => $this->applyBounds(
                $queryBuilder,
                $queryNameGenerator,
                $value,
                ['after' => '>=', 'before' => '<='],
                'MAX(%s.checkoutCompletedAt)',
                $channel,
                $paymentStates,
                self::KEY_LAST_ORDER,
                static fn (mixed $bound): ?\DateTimeImmutable => self::toDate($bound),
            ),
        };
    }

    /**
     * @param array<string, mixed>  $value
     * @param array<string, string> $operators   bound name => SQL operator
     * @param string                $aggregate   sprintf template taking the order alias
     * @param list<string>          $paymentStates
     * @param \Closure(mixed): (int|\DateTimeImmutable|null) $coerce
     */
    private function applyBounds(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        array $value,
        array $operators,
        string $aggregate,
        ?string $channel,
        array $paymentStates,
        string $key,
        \Closure $coerce,
    ): void {
        $rootAlias = self::rootAlias($queryBuilder);

        foreach ($operators as $bound => $operator) {
            $coerced = $coerce($value[$bound] ?? null);
            if (null === $coerced) {
                continue;
            }

            $orderAlias = $queryNameGenerator->generateJoinAlias($key);
            $boundParam = $queryNameGenerator->generateParameterName($key . '_' . $bound);
            $cartParam = $queryNameGenerator->generateParameterName($key . '_cart');
            $statesParam = $queryNameGenerator->generateParameterName($key . '_states');

            $joins = '';
            $conditions = [
                sprintf('%s.customer = %s', $orderAlias, $rootAlias),
                // StateBasedExtension guards the order collection, not this
                // subquery: without it abandoned carts would count as value.
                sprintf('%s.state != :%s', $orderAlias, $cartParam),
                sprintf('%s.paymentState IN (:%s)', $orderAlias, $statesParam),
            ];

            $queryBuilder->setParameter($cartParam, OrderInterface::STATE_CART);
            $queryBuilder->setParameter($statesParam, $paymentStates);

            if (null !== $channel) {
                $channelAlias = $queryNameGenerator->generateJoinAlias($key . '_ch');
                $channelParam = $queryNameGenerator->generateParameterName($key . '_channel');
                $joins = sprintf(' JOIN %s.channel %s', $orderAlias, $channelAlias);
                $conditions[] = sprintf('%s.code = :%s', $channelAlias, $channelParam);
                $queryBuilder->setParameter($channelParam, $channel);
            }

            $subQuery = sprintf(
                'SELECT ' . $aggregate . ' FROM %s %s%s WHERE %s',
                $orderAlias,
                $this->orderClass,
                $orderAlias,
                $joins,
                implode(' AND ', $conditions),
            );

            $queryBuilder
                ->andWhere(sprintf('(%s) %s :%s', $subQuery, $operator, $boundParam))
                ->setParameter($boundParam, $coerced)
            ;
        }
    }

    /**
     * @return list<string>
     */
    private static function paymentStates(mixed $value): array
    {
        $states = self::stringList($value);

        return [] === $states ? [OrderPaymentStates::STATE_PAID] : $states;
    }

    public function getDescription(string $resourceClass): array
    {
        $scope = ' Carts never count; by default only orders whose paymentState is "paid" do.';

        return [
            'ordersTotal[gte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Minimum cumulated order total, in minor units. REQUIRES orderAggregate[channel].' . $scope,
            ],
            'ordersTotal[lte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Maximum cumulated order total, in minor units. REQUIRES orderAggregate[channel].' . $scope,
            ],
            'ordersCount[gte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Minimum number of orders.' . $scope,
            ],
            'ordersCount[lte]' => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Maximum number of orders.' . $scope,
            ],
            'lastOrder[after]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep customers whose most recent order is on or after this date (ISO-8601). Customers with no matching order are excluded.' . $scope,
            ],
            'lastOrder[before]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep customers whose most recent order is on or before this date (ISO-8601) - dormant customers. Customers with no matching order are excluded.' . $scope,
            ],
            'orderAggregate[channel]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Channel code scoping every aggregate above. Required for ordersTotal, optional for the others.',
            ],
            'orderAggregate[paymentStates][]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Payment states that count towards the aggregates (default: paid).',
            ],
        ];
    }
}
