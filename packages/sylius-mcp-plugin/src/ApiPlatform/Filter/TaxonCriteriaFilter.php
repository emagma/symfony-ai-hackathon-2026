<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated search on taxons: `name`, `code`, `parentCode`, `rootOnly`, `enabled`, `subtreeOf`
 * and `depth`. `subtreeOf` walks the nested set, so a whole branch comes back in one request.
 *
 * @internal
 */
final class TaxonCriteriaFilter extends LeanGatedFilter
{
    private const string KEY_NAME = 'name';

    private const string KEY_CODE = 'code';

    private const string KEY_PARENT_CODE = 'parentCode';

    private const string KEY_ROOT_ONLY = 'rootOnly';

    private const string KEY_ENABLED = 'enabled';

    private const string KEY_SUBTREE_OF = 'subtreeOf';

    private const string KEY_DEPTH = 'depth';

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
            self::KEY_NAME => $this->filterName($queryBuilder, $queryNameGenerator, $resourceClass, $value),
            self::KEY_CODE => $this->filterCode($queryBuilder, $queryNameGenerator, $value),
            self::KEY_PARENT_CODE => $this->filterParentCode($queryBuilder, $queryNameGenerator, $value),
            self::KEY_ROOT_ONLY => $this->filterRootOnly($queryBuilder, $value),
            self::KEY_ENABLED => $this->filterEnabled($queryBuilder, $queryNameGenerator, $value),
            self::KEY_SUBTREE_OF => $this->filterSubtreeOf($queryBuilder, $queryNameGenerator, $resourceClass, $value, $context),
            self::KEY_DEPTH => $this->filterDepth($queryBuilder, $queryNameGenerator, $value, $context),
            default => null,
        };
    }

    private function filterName(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        mixed $value,
    ): void {
        $needle = self::nonEmptyString($value);
        if (null === $needle) {
            $this->skip('name filter ignored: expected a non-empty string.');

            return;
        }

        $translationClass = $this->getClassMetadata($resourceClass)->getAssociationTargetClass('translations');
        $translationAlias = $queryNameGenerator->generateJoinAlias('taxon_tr');
        $nameParam = $queryNameGenerator->generateParameterName('taxon_name');

        $predicates = [
            sprintf('%s.translatable = %s', $translationAlias, self::rootAlias($queryBuilder)),
            sprintf('LOWER(%s.name) LIKE :%s', $translationAlias, $nameParam),
        ];
        $queryBuilder->setParameter($nameParam, self::containsPattern($needle));

        $locale = $this->leanRequestDetector->requestedLocale();
        if (null !== $locale) {
            $localeParam = $queryNameGenerator->generateParameterName('taxon_locale');
            $predicates[] = sprintf('%s.locale = :%s', $translationAlias, $localeParam);
            $queryBuilder->setParameter($localeParam, $locale);
        }

        $queryBuilder->andWhere(sprintf(
            'EXISTS (SELECT %s.id FROM %s %s WHERE %s)',
            $translationAlias,
            $translationClass,
            $translationAlias,
            implode(' AND ', $predicates),
        ));
    }

    private function filterCode(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $needle = self::nonEmptyString($value);
        if (null === $needle) {
            $this->skip('code filter ignored: expected a non-empty string.');

            return;
        }

        $param = $queryNameGenerator->generateParameterName('taxon_code');

        $queryBuilder
            ->andWhere(sprintf('LOWER(%s.code) LIKE :%s', self::rootAlias($queryBuilder), $param))
            ->setParameter($param, self::containsPattern($needle))
        ;
    }

    private function filterParentCode(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $code = self::nonEmptyString($value);
        if (null === $code) {
            $this->skip('parentCode filter ignored: expected a non-empty string.');

            return;
        }

        $parentAlias = $queryNameGenerator->generateJoinAlias('taxon_parent');
        $param = $queryNameGenerator->generateParameterName('taxon_parent_code');

        $queryBuilder
            ->innerJoin(sprintf('%s.parent', self::rootAlias($queryBuilder)), $parentAlias)
            ->andWhere(sprintf('%s.code = :%s', $parentAlias, $param))
            ->setParameter($param, $code)
        ;
    }

    private function filterRootOnly(QueryBuilder $queryBuilder, mixed $value): void
    {
        $flag = self::toBool($value);
        if (null === $flag) {
            $this->skip('rootOnly filter ignored: expected a boolean.');

            return;
        }
        if (!$flag) {
            return;
        }

        $queryBuilder->andWhere(sprintf('%s.parent IS NULL', self::rootAlias($queryBuilder)));
    }

    private function filterEnabled(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
    ): void {
        $flag = self::toBool($value);
        if (null === $flag) {
            $this->skip('enabled filter ignored: expected a boolean.');

            return;
        }

        $param = $queryNameGenerator->generateParameterName('taxon_enabled');

        $queryBuilder
            ->andWhere(sprintf('%s.enabled = :%s', self::rootAlias($queryBuilder), $param))
            ->setParameter($param, $flag)
        ;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function filterSubtreeOf(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        mixed $value,
        array $context,
    ): void {
        $code = self::nonEmptyString($value);
        if (null === $code) {
            $this->skip('subtreeOf filter ignored: expected a non-empty string.');

            return;
        }

        $rootAlias = self::rootAlias($queryBuilder);
        $ancestorAlias = $queryNameGenerator->generateJoinAlias('taxon_ancestor');
        $param = $queryNameGenerator->generateParameterName('taxon_subtree_of');

        // The ancestor is joined by code, then the nested-set interval does the
        // rest: a taxon is inside the subtree when its [left, right] lies within
        // the ancestor's, on the same tree root. The ancestor itself satisfies
        // the predicate too (inclusive). An unknown code yields an empty page.
        $queryBuilder
            ->innerJoin($resourceClass, $ancestorAlias, 'WITH', sprintf('%s.code = :%s', $ancestorAlias, $param))
            ->andWhere(sprintf('%s.root = %s.root', $rootAlias, $ancestorAlias))
            ->andWhere(sprintf('%s.left >= %s.left', $rootAlias, $ancestorAlias))
            ->andWhere(sprintf('%s.right <= %s.right', $rootAlias, $ancestorAlias))
            ->setParameter($param, $code)
        ;

        // `depth` is relative to the ancestor, so it is bound here where the
        // ancestor alias exists; filterDepth() steps aside in that case.
        $depth = self::depthOf($context);
        if (null === $depth) {
            return;
        }
        $depthParam = $queryNameGenerator->generateParameterName('taxon_depth');
        $queryBuilder
            ->andWhere(sprintf('%s.level <= %s.level + :%s', $rootAlias, $ancestorAlias, $depthParam))
            ->setParameter($depthParam, $depth)
        ;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function filterDepth(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        mixed $value,
        array $context,
    ): void {
        $depth = self::nonNegativeInt($value);
        if (null === $depth) {
            $this->skip('depth filter ignored: expected an integer >= 0.');

            return;
        }
        $filters = $context['filters'] ?? null;
        if (\is_array($filters) && null !== self::nonEmptyString($filters[self::KEY_SUBTREE_OF] ?? null)) {
            return; // bound relative to the ancestor by filterSubtreeOf()
        }

        $param = $queryNameGenerator->generateParameterName('taxon_depth');

        $queryBuilder
            ->andWhere(sprintf('%s.level <= :%s', self::rootAlias($queryBuilder), $param))
            ->setParameter($param, $depth)
        ;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function depthOf(array $context): ?int
    {
        $filters = $context['filters'] ?? null;
        if (!\is_array($filters) || !\array_key_exists(self::KEY_DEPTH, $filters)) {
            return null;
        }

        return self::nonNegativeInt($filters[self::KEY_DEPTH]);
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (\is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return (string) $int === trim((string) $value) && $int >= 0 ? $int : null;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::KEY_NAME => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Partial, case-insensitive match on the translated name (the locale of the Prefer header when given, any translation otherwise).',
            ],
            self::KEY_CODE => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Partial, case-insensitive match on the code.',
            ],
            self::KEY_PARENT_CODE => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Exact code of the direct parent taxon.',
            ],
            self::KEY_ROOT_ONLY => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' true keeps only the top-level taxons (no parent).',
            ],
            self::KEY_ENABLED => [
                'type' => 'boolean',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Keep only enabled (true) or only disabled (false) taxons.',
            ],
            self::KEY_SUBTREE_OF => [
                'type' => 'string',
                'required' => false,
                'description' => self::LEAN_NOTE . ' Exact taxon code; keeps that taxon and all of its descendants (nested set).',
            ],
            self::KEY_DEPTH => [
                'type' => 'integer',
                'required' => false,
                'description' => self::LEAN_NOTE . ' With subtreeOf, levels below that taxon to keep (0 = the taxon alone); alone, an absolute tree level (0 = the roots).',
            ],
        ];
    }
}
