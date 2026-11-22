<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

/**
 * Orders a lean taxon collection in nested-set order: parents before children,
 * siblings as the admin positions them. Other consumers keep the default order.
 *
 * @internal
 */
final readonly class TaxonTreeOrderExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private LeanRequestDetector $leanRequestDetector,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!is_a($resourceClass, TaxonInterface::class, true)) {
            return;
        }
        if (!$this->leanRequestDetector->prefersLeanResponse()) {
            return;
        }

        // `left` restarts at 1 for every tree root (Sylius keeps `root` for
        // that), so the root goes first or two trees would interleave.
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->addOrderBy(sprintf('%s.root', $rootAlias), 'ASC')
            ->addOrderBy(sprintf('%s.left', $rootAlias), 'ASC')
        ;
    }
}
