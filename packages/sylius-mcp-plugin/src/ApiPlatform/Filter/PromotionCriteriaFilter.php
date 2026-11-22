<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated search on promotions: `name`, `code`, `channel`, `couponBased`, `exclusive` and
 * `activeAt`, the promotions whose window contains a date.
 *
 * @internal
 */
final class PromotionCriteriaFilter extends LeanGatedFilter
{
    private const string KEY_NAME = 'name';

    private const string KEY_CODE = 'code';

    private const string KEY_CHANNEL = 'channel';

    private const string KEY_COUPON_BASED = 'couponBased';

    private const string KEY_EXCLUSIVE = 'exclusive';

    private const string KEY_ACTIVE_AT = 'activeAt';

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
            self::KEY_NAME => $this->filterPartial($queryBuilder, $queryNameGenerator, $value, 'name'),
            self::KEY_CODE => $this->filterPartial($queryBuilder, $queryNameGenerator, $value, 'code'),
            self::KEY_CHANNEL => $this->filterChannel($queryBuilder, $queryNameGenerator, $value),
            self::KEY_COUPON_BASED => $this->filterFlag($queryBuilder, $queryNameGenerator, $value, 'couponBased'),
            self::KEY_EXCLUSIVE => $this->filterFlag($queryBuilder, $queryNameGenerator, $value, 'exclusive'),
            self::KEY_ACTIVE_AT => $this->filterActiveAt($queryBuilder, $queryNameGenerator, $value),
            default => null,
        };
    }

    private function filterPartial(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
        string $field,
    ): void {
        $needle = self::nonEmptyString($value);
        if (null === $needle) {
            $this->skip(sprintf('%s filter ignored: expected a non-empty string.', $field));

            return;
        }

        $param = $queryNameGenerator->generateParameterName($field);

        $queryBuilder
            ->andWhere(sprintf('LOWER(%s.%s) LIKE :%s', self::rootAlias($queryBuilder), $field, $param))
            ->setParameter($param, self::containsPattern($needle))
        ;
    }

    private function filterChannel(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $code = self::nonEmptyString($value);
        if (null === $code) {
            $this->skip('channel filter ignored: expected a channel code.');

            return;
        }

        $channelAlias = $queryNameGenerator->generateJoinAlias('promo_channel');
        $param = $queryNameGenerator->generateParameterName('channel_code');

        $queryBuilder
            ->innerJoin(sprintf('%s.channels', self::rootAlias($queryBuilder)), $channelAlias)
            ->andWhere(sprintf('%s.code = :%s', $channelAlias, $param))
            ->setParameter($param, $code)
            // channels is a to-many association: without this a promotion
            // enabled on several channels would come back once per row.
            ->distinct()
        ;
    }

    private function filterFlag(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
        string $field,
    ): void {
        $flag = self::toBool($value);
        if (null === $flag) {
            $this->skip(sprintf('%s filter ignored: expected a boolean.', $field));

            return;
        }

        $param = $queryNameGenerator->generateParameterName($field);

        $queryBuilder
            ->andWhere(sprintf('%s.%s = :%s', self::rootAlias($queryBuilder), $field, $param))
            ->setParameter($param, $flag)
        ;
    }

    private function filterActiveAt(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $date = self::toDate($value);
        if (null === $date) {
            $this->skip('activeAt filter ignored: expected an ISO-8601 date.');

            return;
        }

        $param = $queryNameGenerator->generateParameterName('active_at');

        // A null bound is "no bound" in Sylius, not "excluded" - a promotion
        // with neither date runs forever and must stay in the result.
        $queryBuilder
            ->andWhere(sprintf(
                '(%1$s.startsAt IS NULL OR %1$s.startsAt <= :%2$s) AND (%1$s.endsAt IS NULL OR %1$s.endsAt >= :%2$s)',
                self::rootAlias($queryBuilder),
                $param,
            ))
            ->setParameter($param, $date)
        ;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::KEY_NAME => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep promotions whose name contains this text (case-insensitive).',
            ],
            self::KEY_CODE => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep promotions whose code contains this text.',
            ],
            self::KEY_CHANNEL => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep promotions enabled on this channel code.',
            ],
            self::KEY_COUPON_BASED => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' true keeps promotions that require a coupon, false the automatic ones.',
            ],
            self::KEY_EXCLUSIVE => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' true keeps promotions that cannot be combined with another.',
            ],
            self::KEY_ACTIVE_AT => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep promotions whose schedule covers this date (ISO-8601).'
                    . ' A missing start or end date counts as unbounded.',
            ],
        ];
    }
}
