<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared harness for the lean-gated `/admin/orders` filters.
 *
 * These filters only ever touch the QueryBuilder, so mocking it and recording
 * the calls is enough to pin the DQL they produce without a database.
 */
abstract class OrderFilterTestCase extends TestCase
{
    protected function leanDetector(bool $lean): LeanRequestDetector
    {
        $request = new Request();
        if ($lean) {
            $request->headers->set('Prefer', 'respond-for=lean');
        }

        $stack = new RequestStack();
        $stack->push($request);

        return new LeanRequestDetector($stack);
    }

    protected function managerRegistry(): ManagerRegistry&MockObject
    {
        return $this->createMock(ManagerRegistry::class);
    }

    protected function mockQueryBuilder(): QueryBuilder&MockObject
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['o']);

        return $qb;
    }

    protected function mockQng(): QueryNameGeneratorInterface&MockObject
    {
        $qng = $this->createMock(QueryNameGeneratorInterface::class);

        // Closures with a by-reference `use`, not arrow functions: `fn` captures
        // by value, so the counter would reset on every call and every alias
        // would collide - which is exactly the bug a filter must not have.
        $counter = 0;
        $qng->method('generateJoinAlias')->willReturnCallback(
            static function (string $prefix) use (&$counter): string {
                return $prefix . '_' . ++$counter;
            },
        );
        $qng->method('generateParameterName')->willReturnCallback(
            static function (string $prefix) use (&$counter): string {
                return $prefix . '_' . ++$counter;
            },
        );

        return $qng;
    }

    protected function silentLogger(): LoggerInterface&MockObject
    {
        return $this->createMock(LoggerInterface::class);
    }

    /**
     * Records the DQL fragments a filter appends.
     *
     * @return \ArrayObject<string, array<int|string, mixed>>
     */
    protected function capture(QueryBuilder&MockObject $qb): \ArrayObject
    {
        /** @var \ArrayObject<string, array<int|string, mixed>> $captured */
        $captured = new \ArrayObject(['wheres' => [], 'params' => [], 'joins' => [], 'orderBy' => []]);

        $qb->method('andWhere')->willReturnCallback(
            static function (...$args) use ($captured, $qb): QueryBuilder {
                $wheres = $captured['wheres'];
                $wheres[] = (string) ($args[0] ?? '');
                $captured['wheres'] = $wheres;

                return $qb;
            },
        );

        $qb->method('setParameter')->willReturnCallback(
            static function (...$args) use ($captured, $qb): QueryBuilder {
                $params = $captured['params'];
                $params[(string) ($args[0] ?? '')] = $args[1] ?? null;
                $captured['params'] = $params;

                return $qb;
            },
        );

        foreach (['innerJoin' => 'inner', 'leftJoin' => 'left'] as $method => $kind) {
            $qb->method($method)->willReturnCallback(
                static function (...$args) use ($captured, $qb, $kind): QueryBuilder {
                    $joins = $captured['joins'];
                    $joins[] = [
                        'kind' => $kind,
                        'target' => (string) ($args[0] ?? ''),
                        'alias' => (string) ($args[1] ?? ''),
                        'condition' => null === ($args[3] ?? null) ? null : (string) $args[3],
                    ];
                    $captured['joins'] = $joins;

                    return $qb;
                },
            );
        }

        $qb->method('addOrderBy')->willReturnCallback(
            static function (...$args) use ($captured, $qb): QueryBuilder {
                $orderBy = $captured['orderBy'];
                $orderBy[] = ['sort' => (string) ($args[0] ?? ''), 'order' => (string) ($args[1] ?? '')];
                $captured['orderBy'] = $orderBy;

                return $qb;
            },
        );

        return $captured;
    }
}
