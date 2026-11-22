<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated search on customers: `createdAt[after|before]`, `subscribedToNewsletter`,
 * `hasAccount` and `groupCode`.
 *
 * @internal
 */
final class CustomerCriteriaFilter extends LeanGatedFilter
{
    private const string KEY_CREATED_AT = 'createdAt';

    private const string KEY_NEWSLETTER = 'subscribedToNewsletter';

    private const string KEY_HAS_ACCOUNT = 'hasAccount';

    private const string KEY_GROUP_CODE = 'groupCode';

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
            self::KEY_CREATED_AT => $this->filterCreatedAt($queryBuilder, $queryNameGenerator, $value),
            self::KEY_NEWSLETTER => $this->filterNewsletter($queryBuilder, $queryNameGenerator, $value),
            self::KEY_HAS_ACCOUNT => $this->filterHasAccount($queryBuilder, $queryNameGenerator, $value),
            self::KEY_GROUP_CODE => $this->filterGroupCode($queryBuilder, $queryNameGenerator, $value),
            default => null,
        };
    }

    private function filterCreatedAt(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        if (!\is_array($value)) {
            $this->skip('createdAt filter ignored: expected [after|before] bounds.');

            return;
        }

        $rootAlias = self::rootAlias($queryBuilder);

        foreach (['after' => '>=', 'before' => '<='] as $bound => $operator) {
            $date = self::toDate($value[$bound] ?? null);
            if (null === $date) {
                continue;
            }

            $param = $queryNameGenerator->generateParameterName('created_' . $bound);
            $queryBuilder
                ->andWhere(sprintf('%s.createdAt %s :%s', $rootAlias, $operator, $param))
                ->setParameter($param, $date)
            ;
        }
    }

    private function filterNewsletter(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $flag = self::toBool($value);
        if (null === $flag) {
            $this->skip('subscribedToNewsletter filter ignored: expected a boolean.');

            return;
        }

        $param = $queryNameGenerator->generateParameterName('newsletter');

        $queryBuilder
            ->andWhere(sprintf('%s.subscribedToNewsletter = :%s', self::rootAlias($queryBuilder), $param))
            ->setParameter($param, $flag)
        ;
    }

    private function filterHasAccount(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $flag = self::toBool($value);
        if (null === $flag) {
            $this->skip('hasAccount filter ignored: expected a boolean.');

            return;
        }

        $userAlias = $queryNameGenerator->generateJoinAlias('shop_user');

        // A Sylius customer exists independently of a shop user: guest checkout
        // creates the former without the latter.
        //
        // The join is not optional. Customer::$user is the INVERSE side of the
        // association, and Doctrine refuses a single-valued path expression to
        // an inverse side - `c.user IS NULL` is a semantical error, not a
        // slower query. Testing the joined alias is the supported form.
        $queryBuilder
            ->leftJoin(sprintf('%s.user', self::rootAlias($queryBuilder)), $userAlias)
            ->andWhere(sprintf('%s.id IS %sNULL', $userAlias, $flag ? 'NOT ' : ''))
        ;
    }

    private function filterGroupCode(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $code = self::nonEmptyString($value);
        if (null === $code) {
            $this->skip('groupCode filter ignored: expected a non-empty string.');

            return;
        }

        $groupAlias = $queryNameGenerator->generateJoinAlias('grp');
        $param = $queryNameGenerator->generateParameterName('group_code');

        $queryBuilder
            ->innerJoin(sprintf('%s.group', self::rootAlias($queryBuilder)), $groupAlias)
            ->andWhere(sprintf('%s.code = :%s', $groupAlias, $param))
            ->setParameter($param, $code)
        ;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'createdAt[after]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep customers created on or after this date (ISO-8601).',
            ],
            'createdAt[before]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep customers created on or before this date (ISO-8601).',
            ],
            'subscribedToNewsletter' => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Newsletter consent.',
            ],
            'hasAccount' => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' true keeps registered customers, false keeps guests who checked out without an account.',
            ],
            'groupCode' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Exact customer group CODE (the vendor filter matches the editable name instead).',
            ],
        ];
    }
}
