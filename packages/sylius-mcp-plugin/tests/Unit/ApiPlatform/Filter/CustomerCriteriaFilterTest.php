<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Filter;

use Emagma\SyliusMcpPlugin\ApiPlatform\Filter\CustomerCriteriaFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Customer;

#[CoversClass(CustomerCriteriaFilter::class)]
final class CustomerCriteriaFilterTest extends OrderFilterTestCase
{
    public function testItIsANoOpOutsideLeanMode(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(lean: false)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['createdAt' => ['after' => '2026-01-01'], 'hasAccount' => true],
        ]);
    }

    public function testItIgnoresUnrelatedKeys(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['email' => 'ada', 'group.name' => 'Wholesale'],
        ]);
    }

    public function testItAppliesBothSignupDateBounds(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['createdAt' => ['after' => '2026-01-01', 'before' => '2026-01-31']],
        ]);

        self::assertCount(2, $captured['wheres']);
        self::assertStringStartsWith('o.createdAt >= :', $captured['wheres'][0]);
        self::assertStringStartsWith('o.createdAt <= :', $captured['wheres'][1]);

        $params = array_values($captured['params']);
        self::assertInstanceOf(\DateTimeImmutable::class, $params[0]);
        self::assertSame('2026-01-01', $params[0]->format('Y-m-d'));
        self::assertSame('2026-01-31', $params[1]->format('Y-m-d'));
    }

    public function testItIgnoresAnUnparsableDate(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['createdAt' => ['after' => 'last tuesday-ish']],
        ]);
    }

    public function testItRejectsADateThatPhpParsesWithWarnings(): void
    {
        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        // PHP resolves this to NOW with a warning instead of throwing; taking
        // it at face value would silently answer a different question.
        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['createdAt' => ['after' => 'a while ago']],
        ]);
    }

    public function testItLogsAndSkipsAScalarDateValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('[after|before] bounds'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['createdAt' => '2026-01-01'],
        ]);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function booleanProvider(): array
    {
        return [
            'real true' => [true, true],
            'real false' => [false, false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'one' => ['1', true],
            'zero' => ['0', false],
        ];
    }

    #[DataProvider('booleanProvider')]
    public function testItParsesNewsletterConsentFromQueryStrings(mixed $input, bool $expected): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['subscribedToNewsletter' => $input],
        ]);

        self::assertStringStartsWith('o.subscribedToNewsletter = :', $captured['wheres'][0]);
        self::assertSame([$expected], array_values($captured['params']));
    }

    public function testItIgnoresAnUnparsableBoolean(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('expected a boolean'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('andWhere');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['subscribedToNewsletter' => 'maybe'],
        ]);
    }

    public function testHasAccountTrueKeepsRegisteredCustomers(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['hasAccount' => 'true'],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('o.user', $captured['joins'][0]['target']);
        self::assertStringEndsWith('.id IS NOT NULL', $captured['wheres'][0]);
    }

    public function testHasAccountFalseKeepsGuests(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        // A Sylius customer exists without a shop user after guest checkout.
        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['hasAccount' => 'false'],
        ]);

        self::assertStringEndsWith('.id IS NULL', $captured['wheres'][0]);
    }

    public function testHasAccountJoinsInsteadOfDereferencingTheInverseSide(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['hasAccount' => 'true'],
        ]);

        // Customer::$user is the INVERSE side, and Doctrine rejects a
        // single-valued path expression to one: `o.user IS NULL` is a
        // semantical error at query time, which no mocked QueryBuilder can
        // surface. The join is the supported form, and a LEFT one so that
        // hasAccount=false can still match rows.
        self::assertSame('left', $captured['joins'][0]['kind']);
        self::assertStringNotContainsString('o.user IS', $captured['wheres'][0]);
    }

    public function testItJoinsTheGroupToMatchItsCode(): void
    {
        $qb = $this->mockQueryBuilder();
        $captured = $this->capture($qb);

        $this->filter()->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['groupCode' => ' wholesale '],
        ]);

        self::assertCount(1, $captured['joins']);
        self::assertSame('o.group', $captured['joins'][0]['target']);
        self::assertStringContainsString('.code = :', $captured['wheres'][0]);
        self::assertSame(['wholesale'], array_values($captured['params']));
    }

    public function testItSkipsABlankGroupCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('groupCode'));

        $qb = $this->mockQueryBuilder();
        $qb->expects(self::never())->method('innerJoin');

        $this->filter(logger: $logger)->apply($qb, $this->mockQng(), Customer::class, null, [
            'filters' => ['groupCode' => '  '],
        ]);
    }

    public function testItDocumentsEveryKeyAsLeanOnly(): void
    {
        $description = $this->filter()->getDescription(Customer::class);

        self::assertSame([
            'createdAt[after]', 'createdAt[before]',
            'subscribedToNewsletter', 'hasAccount', 'groupCode',
        ], array_keys($description));
        foreach ($description as $key => $meta) {
            self::assertStringContainsString('Lean mode only', $meta['description'], $key);
        }
    }

    private function filter(bool $lean = true, ?LoggerInterface $logger = null): CustomerCriteriaFilter
    {
        return new CustomerCriteriaFilter(
            $this->leanDetector($lean),
            $this->managerRegistry(),
            $logger ?? $this->silentLogger(),
        );
    }
}
