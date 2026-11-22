<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated search on orders: the three states, the `number` and the `customerEmail`.
 *
 * @internal
 */
final class OrderCriteriaFilter extends LeanGatedFilter
{
    private const string KEY_STATE = 'state';

    private const string KEY_PAYMENT_STATE = 'paymentState';

    private const string KEY_SHIPPING_STATE = 'shippingState';

    private const string KEY_NUMBER = 'number';

    private const string KEY_CUSTOMER_EMAIL = 'customerEmail';

    /** @var array<string, string> query key => Order property */
    private const STATE_PROPERTIES = [
        self::KEY_STATE => 'state',
        self::KEY_PAYMENT_STATE => 'paymentState',
        self::KEY_SHIPPING_STATE => 'shippingState',
    ];

    protected function filterLeanProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation,
        array $context,
    ): void {
        if (isset(self::STATE_PROPERTIES[$property])) {
            $this->filterStateIn($queryBuilder, $queryNameGenerator, self::STATE_PROPERTIES[$property], $value);

            return;
        }

        if (self::KEY_NUMBER === $property) {
            $this->filterNumber($queryBuilder, $queryNameGenerator, $value);

            return;
        }

        if (self::KEY_CUSTOMER_EMAIL === $property) {
            $this->filterCustomerEmail($queryBuilder, $queryNameGenerator, $value);
        }
    }

    private function filterStateIn(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $field,
        mixed $value,
    ): void {
        $states = self::stringList($value);
        if ([] === $states) {
            $this->skip(sprintf('%s filter ignored: no usable value.', $field));

            return;
        }

        $param = $queryNameGenerator->generateParameterName($field);

        $queryBuilder
            ->andWhere(sprintf('%s.%s IN (:%s)', self::rootAlias($queryBuilder), $field, $param))
            ->setParameter($param, $states)
        ;
    }

    private function filterNumber(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $needle = self::nonEmptyString($value);
        if (null === $needle) {
            $this->skip('number filter ignored: expected a non-empty string.');

            return;
        }

        $param = $queryNameGenerator->generateParameterName(self::KEY_NUMBER);

        // Order numbers are zero-padded ("000000042"), so an operator asking
        // for "42" must still match.
        $queryBuilder
            ->andWhere(sprintf('LOWER(%s.number) LIKE :%s', self::rootAlias($queryBuilder), $param))
            ->setParameter($param, self::containsPattern($needle))
        ;
    }

    private function filterCustomerEmail(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $needle = self::nonEmptyString($value);
        if (null === $needle) {
            $this->skip('customerEmail filter ignored: expected a non-empty string.');

            return;
        }

        $customerAlias = $queryNameGenerator->generateJoinAlias('cust');
        $param = $queryNameGenerator->generateParameterName('customer_email');

        $queryBuilder
            ->innerJoin(sprintf('%s.customer', self::rootAlias($queryBuilder)), $customerAlias)
            // emailCanonical is Sylius's own lowercased copy, so matching on it
            // makes the search case-insensitive without a function on the
            // column killing the index.
            ->andWhere(sprintf('%s.emailCanonical LIKE :%s', $customerAlias, $param))
            ->setParameter($param, self::containsPattern($needle))
        ;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'state[]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Order states to keep (IN). Typical values: new, fulfilled, cancelled. Carts are already excluded by Sylius.',
            ],
            'paymentState[]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Payment states to keep (IN). Typical values: awaiting_payment, partially_paid, paid, refunded, cancelled.',
            ],
            'shippingState[]' => [
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => self::LEAN_NOTE . ' Shipping states to keep (IN). Typical values: ready, partially_shipped, shipped, cancelled.',
            ],
            'number' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Partial, case-insensitive match on the order number. Numbers are zero-padded, so "42" matches "000000042".',
            ],
            'customerEmail' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Partial, case-insensitive match on the customer email.',
            ],
        ];
    }
}
