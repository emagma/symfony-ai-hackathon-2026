<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Lean-gated sort of the orders: `sort[by]` (`checkoutCompletedAt`, `total` or `number`) and
 * `sort[direction]`, descending by default. Sylius's own `order` filter stays untouched.
 *
 * @internal
 */
final class OrderSortFilter extends LeanGatedFilter
{
    private const string KEY = 'sort';

    /** @var list<string> */
    private const SORTABLE = ['checkoutCompletedAt', 'total', 'number'];

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

        $by = $value['by'] ?? null;
        if (!\is_string($by) || !\in_array($by, self::SORTABLE, true)) {
            $this->skip(sprintf('sort filter ignored: "by" must be one of %s.', implode(', ', self::SORTABLE)));

            return;
        }

        $direction = \is_string($value['direction'] ?? null) ? mb_strtolower($value['direction']) : 'desc';
        if (!\in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        // add, not orderBy: the vendor operation declares its own default
        // ordering (number DESC) and replacing it wholesale would make the
        // result order depend on whether this filter ran.
        $queryBuilder->addOrderBy(sprintf('%s.%s', self::rootAlias($queryBuilder), $by), $direction);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'sort[by]' => [
                'type' => 'string',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => self::SORTABLE],
                'description' => self::LEAN_NOTE . ' Field to order by: ' . implode(', ', self::SORTABLE) . '.',
            ],
            'sort[direction]' => [
                'type' => 'string',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'description' => self::LEAN_NOTE . ' asc or desc (default desc).',
            ],
        ];
    }
}
