<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Psr\Log\LoggerInterface;

/**
 * Base of every lean filter: nothing happens outside lean mode, a malformed value is skipped
 * and logged, never a 400, and the coercions are shared. Extend it to add a filter.
 */
abstract class LeanGatedFilter extends AbstractFilter
{
    /** Prefix of every parameter description this filter family exposes in OpenAPI. */
    protected const string LEAN_NOTE = '[Lean mode only, requires Prefer: respond-for=lean]';

    public function __construct(
        protected readonly LeanRequestDetector $leanRequestDetector,
        ManagerRegistry $managerRegistry,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($managerRegistry, $logger);
    }

    /**
     * AbstractFilter::apply() calls this once per top-level query key.
     */
    final protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->leanRequestDetector->prefersLeanResponse()) {
            return;
        }

        $this->filterLeanProperty($property, $value, $queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
    }

    /**
     * Same contract as {@see AbstractFilter::filterProperty()}, reached only in
     * lean mode. Keys the filter does not own must be ignored silently: every
     * filter of an operation sees every query key.
     *
     * @param array<string, mixed> $context
     */
    abstract protected function filterLeanProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation,
        array $context,
    ): void;

    /**
     * Records why a query key was ignored. Info level on purpose: this is the
     * caller's input, not the shop's health.
     *
     * @param array<string, mixed> $context
     */
    protected function skip(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    protected static function rootAlias(QueryBuilder $queryBuilder): string
    {
        return $queryBuilder->getRootAliases()[0];
    }

    protected static function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * Accepts a single value or a list, keeps the non-empty strings, once each.
     *
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        $candidates = \is_array($value) ? $value : [$value];

        $out = [];
        foreach ($candidates as $candidate) {
            $string = self::nonEmptyString($candidate);
            if (null !== $string) {
                $out[] = $string;
            }
        }

        return array_values(array_unique($out));
    }

    protected static function toBool(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }
        if (!\is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    protected static function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A date is accepted only when PHP parsed it with zero warnings and zero
     * errors. `new \DateTimeImmutable()` only throws on input it cannot make
     * any sense of; anything it can partially interpret comes back as a real
     * date with a warning nobody reads.
     */
    protected static function toDate(mixed $value): ?\DateTimeImmutable
    {
        $string = self::nonEmptyString($value);
        if (null === $string) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($string);
        } catch (\Exception) {
            return null;
        }

        // false when the parse was perfectly clean, populated counts otherwise.
        $errors = \DateTimeImmutable::getLastErrors();
        if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }

    /**
     * The user's needle is data, not a pattern: a `%` or `_` in it would
     * otherwise widen the match silently.
     */
    protected static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * `%needle%`, lower-cased and escaped, for a case-insensitive partial match.
     */
    protected static function containsPattern(string $needle): string
    {
        return '%' . self::escapeLike(mb_strtolower($needle)) . '%';
    }
}
