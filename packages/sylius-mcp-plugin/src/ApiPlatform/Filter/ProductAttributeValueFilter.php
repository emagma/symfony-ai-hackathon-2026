<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Psr\Log\LoggerInterface;
use Sylius\Component\Attribute\Model\AttributeValueInterface;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Repository\ProductAttributeRepositoryInterface;

/**
 * Lean-gated search on the attribute values of a product: `attribute[<code>]`,
 * `attribute_present[]` and `attribute_range[<code>][gte|lte]`. Each entry adds its own join.
 *
 * @internal
 */
final class ProductAttributeValueFilter extends LeanGatedFilter
{
    private const string KEY_EQ = 'attribute';

    private const string KEY_PRESENT = 'attribute_present';

    private const string KEY_RANGE = 'attribute_range';

    /** @var list<string> */
    private const array RANGEABLE_STORAGE_TYPES = [
        AttributeValueInterface::STORAGE_INTEGER,
        AttributeValueInterface::STORAGE_FLOAT,
        AttributeValueInterface::STORAGE_DATE,
        AttributeValueInterface::STORAGE_DATETIME,
    ];

    public function __construct(
        LeanRequestDetector $leanRequestDetector,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly string $defaultLocale,
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
        if (!\in_array($property, [self::KEY_EQ, self::KEY_PRESENT, self::KEY_RANGE], true)) {
            return;
        }
        if (!\is_array($value) || [] === $value) {
            return;
        }

        $rootAlias = self::rootAlias($queryBuilder);
        $locale = $this->resolveLocale($context);

        match ($property) {
            self::KEY_EQ => $this->applyEqualityFilters($value, $queryBuilder, $queryNameGenerator, $rootAlias, $locale),
            self::KEY_PRESENT => $this->applyPresenceFilters($value, $queryBuilder, $queryNameGenerator, $rootAlias, $locale),
            self::KEY_RANGE => $this->applyRangeFilters($value, $queryBuilder, $queryNameGenerator, $rootAlias, $locale),
        };
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::KEY_EQ . '[<code>]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Exact match on a product attribute value by attribute code. Value is coerced based on the attribute storage type.',
            ],
            self::KEY_PRESENT . '[]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Restrict to products that have a value for the given attribute code, regardless of the value.',
            ],
            self::KEY_RANGE . '[<code>][gte]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Lower bound (inclusive) on a numeric or date attribute.',
            ],
            self::KEY_RANGE . '[<code>][lte]' => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Upper bound (inclusive) on a numeric or date attribute.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function applyEqualityFilters(
        array $entries,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $rootAlias,
        string $locale,
    ): void {
        foreach ($entries as $code => $rawValue) {
            $attribute = $this->resolveAttribute((string) $code);
            if (null === $attribute) {
                continue;
            }
            $storageType = $attribute->getStorageType();
            if (null === $storageType) {
                $this->skip('Attribute "{code}" has no storage type; skipping equality filter.', ['code' => $code]);

                continue;
            }

            $alias = $this->joinAttribute($qb, $qng, $rootAlias, $attribute, $locale);
            [$expression, $paramName, $paramValue] = $this->buildEqualityCondition($alias, $storageType, $rawValue, $qng);
            if (null === $expression) {
                continue;
            }

            $qb->andWhere($expression);
            $qb->setParameter($paramName, $paramValue);
        }
    }

    /**
     * @param array<int|string, mixed> $entries
     */
    private function applyPresenceFilters(
        array $entries,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $rootAlias,
        string $locale,
    ): void {
        foreach (self::stringList($entries) as $code) {
            $attribute = $this->resolveAttribute($code);
            if (null === $attribute) {
                continue;
            }

            $alias = $this->joinAttribute($qb, $qng, $rootAlias, $attribute, $locale);
            $qb->andWhere(sprintf('%s.id IS NOT NULL', $alias));
        }
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function applyRangeFilters(
        array $entries,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $rootAlias,
        string $locale,
    ): void {
        foreach ($entries as $code => $bounds) {
            if (!\is_array($bounds)) {
                continue;
            }
            $attribute = $this->resolveAttribute((string) $code);
            if (null === $attribute) {
                continue;
            }
            $storageType = $attribute->getStorageType();
            if (null === $storageType) {
                continue;
            }
            if (!\in_array($storageType, self::RANGEABLE_STORAGE_TYPES, true)) {
                $this->skip(
                    'Range filter requested on attribute "{code}" with non-rangeable storage type "{type}"; skipping.',
                    ['code' => $code, 'type' => $storageType],
                );

                continue;
            }

            $alias = $this->joinAttribute($qb, $qng, $rootAlias, $attribute, $locale);
            foreach (['gte' => '>=', 'lte' => '<='] as $key => $operator) {
                if (!isset($bounds[$key])) {
                    continue;
                }
                $coerced = $this->coerceRangeBound($bounds[$key], $storageType);
                if (null === $coerced) {
                    continue;
                }
                $paramName = $qng->generateParameterName(sprintf('%s_%s', $code, $key));
                $qb->andWhere(sprintf('%s.%s %s :%s', $alias, $storageType, $operator, $paramName));
                $qb->setParameter($paramName, $coerced);
            }
        }
    }

    private function joinAttribute(
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $rootAlias,
        ProductAttributeInterface $attribute,
        string $locale,
    ): string {
        $alias = $qng->generateJoinAlias('pav');
        $attrParam = $qng->generateParameterName('pav_attr');
        $localeParam = $qng->generateParameterName('pav_locale');

        $qb->leftJoin(
            sprintf('%s.attributes', $rootAlias),
            $alias,
            'WITH',
            sprintf(
                '%1$s.attribute = :%2$s AND (%1$s.localeCode = :%3$s OR %1$s.localeCode IS NULL)',
                $alias,
                $attrParam,
                $localeParam,
            ),
        );
        $qb->setParameter($attrParam, $attribute);
        $qb->setParameter($localeParam, $locale);

        return $alias;
    }

    /**
     * @return array{0: string|null, 1: string, 2: mixed}
     */
    private function buildEqualityCondition(
        string $alias,
        string $storageType,
        mixed $rawValue,
        QueryNameGeneratorInterface $qng,
    ): array {
        $paramName = $qng->generateParameterName(sprintf('%s_val', $alias));

        // STORAGE_JSON (select type) - values are stored as JSON arrays of
        // choice keys. Use the same CAST+LIKE pattern Sylius itself uses in
        // ProductAttributeValueRepository::findByJsonChoiceKey().
        if (AttributeValueInterface::STORAGE_JSON === $storageType) {
            return [
                sprintf('CAST(%s.json AS text) LIKE :%s', $alias, $paramName),
                $paramName,
                '%"' . $this->stringifyForJsonLike($rawValue) . '"%',
            ];
        }

        $coerced = $this->coerceEqualityValue($rawValue, $storageType);
        if (null === $coerced) {
            return [null, $paramName, null];
        }

        return [
            sprintf('%s.%s = :%s', $alias, $storageType, $paramName),
            $paramName,
            $coerced,
        ];
    }

    private function coerceEqualityValue(mixed $value, string $storageType): mixed
    {
        return match ($storageType) {
            AttributeValueInterface::STORAGE_TEXT => \is_scalar($value) ? (string) $value : null,
            AttributeValueInterface::STORAGE_INTEGER => self::toInt($value),
            AttributeValueInterface::STORAGE_FLOAT => \is_numeric($value) ? (float) $value : null,
            AttributeValueInterface::STORAGE_BOOLEAN => self::toBool($value),
            AttributeValueInterface::STORAGE_DATE, AttributeValueInterface::STORAGE_DATETIME => self::toDate($value),
            default => null,
        };
    }

    private function coerceRangeBound(mixed $value, string $storageType): mixed
    {
        return match ($storageType) {
            AttributeValueInterface::STORAGE_INTEGER => self::toInt($value),
            AttributeValueInterface::STORAGE_FLOAT => \is_numeric($value) ? (float) $value : null,
            AttributeValueInterface::STORAGE_DATE, AttributeValueInterface::STORAGE_DATETIME => self::toDate($value),
            default => null,
        };
    }

    private function stringifyForJsonLike(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        // Escape any embedded double quote so the LIKE pattern stays valid.
        return str_replace('"', '\\"', (string) $value);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveLocale(array $context): string
    {
        $filters = $context['filters'] ?? [];
        if (\is_array($filters) && isset($filters['locale']) && \is_string($filters['locale']) && '' !== $filters['locale']) {
            return $filters['locale'];
        }

        return $this->defaultLocale;
    }

    private function resolveAttribute(string $code): ?ProductAttributeInterface
    {
        if ('' === $code) {
            return null;
        }
        /** @var ProductAttributeInterface|null $attribute */
        $attribute = $this->attributeRepository->findOneBy(['code' => $code]);
        if (null === $attribute) {
            $this->skip('Unknown product attribute code "{code}"; skipping filter.', ['code' => $code]);

            return null;
        }

        return $attribute;
    }
}
