<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared\Batch;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Server\RequestContext;
use Symfony\Component\Uid\Uuid;

/**
 * Same shape and rules as {@see BatchUpsertTrait}: a trait because the
 * confirmation gate reads the tool's own #[Permission].
 *
 * @internal
 */
trait BatchDeleteTrait
{
    private const int BATCH_MAX_ITEMS = 50;

    /** The audit target type, e.g. `tax_category`. */
    abstract private function targetType(): string;

    /** What the human reads: `tax categories`. */
    abstract private function label(): string;

    /**
     * The human name of one resource, for the confirmation text.
     *
     * @param array<string, mixed> $current
     */
    abstract private function nameOf(array $current): ?string;

    /**
     * @param list<string> $keys
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}>
     */
    private function probe(Route $collection, array $keys): array
    {
        $paths = [];
        foreach ($keys as $key) {
            $paths[$key] = $collection->withKey($key);
        }

        return $this->client->getDecodedMany($paths);
    }

    /**
     * A reason to refuse this item before proposing the batch, or null.
     *
     * @param array<string, mixed> $current
     */
    private function guard(string $key, array $current): ?string
    {
        return null;
    }

    /**
     * A resource addressed by a database id overrides this and reads the id off `$current`.
     *
     * @param array<string, mixed> $current
     */
    private function deletePath(Route $collection, string $key, array $current): Route
    {
        return $collection->withKey($key);
    }

    /**
     * @param array{ok: bool, status?: int|null, error?: string, body?: array<string, mixed>|null} $outcome
     *
     * @return array<string, mixed>
     */
    private function deleteFailure(array $outcome): array
    {
        return ApiFailure::fromResult($outcome, ['action' => 'failed']);
    }

    /**
     * @param list<mixed> $keys
     *
     * @return array<string, mixed>
     */
    private function runDelete(string $tool, Route $collection, array $keys, bool $confirm, ?RequestContext $context): array
    {
        $total = \count($keys);
        if (0 === $total) {
            return self::deleteEnvelope(null, []);
        }
        if ($total > self::BATCH_MAX_ITEMS) {
            return ['ok' => false, 'total' => $total, 'reason' => 'too_many_items', 'hint' => \sprintf('At most %d codes per call; split the batch.', self::BATCH_MAX_ITEMS)];
        }

        $errors = [];
        $unique = [];
        foreach ($keys as $index => $key) {
            if (!\is_string($key) || '' === trim($key)) {
                $errors[] = ['index' => $index, 'message' => 'a code is a non-empty string'];

                continue;
            }
            if (isset($unique[$key])) {
                $errors[] = ['index' => $index, 'code' => $key, 'message' => 'code appears twice in the batch'];

                continue;
            }
            $unique[$key] = true;
        }
        if ([] !== $errors) {
            return ['ok' => false, 'total' => $total, 'reason' => 'items_invalid', 'errors' => $errors];
        }

        $probes = $this->probe($collection, array_keys($unique));

        $results = [];
        $deletes = [];
        foreach (array_keys($unique) as $key) {
            $probe = $probes[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            if (true !== $probe['ok']) {
                $results[$key] = ApiFailure::fromResult($probe, ['action' => 'failed']);

                continue;
            }
            $current = $probe['body'];
            $refused = $this->guard((string) $key, $current);
            if (null !== $refused) {
                $results[$key] = ['ok' => false, 'action' => 'failed', 'reason' => $refused];

                continue;
            }
            $deletes[$key] = $current;
        }

        if ([] === $deletes) {
            return self::deleteEnvelope(null, self::deleteInOrder($unique, $results));
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            \sprintf('Confirm the deletion of %d %s', \count($deletes), $this->label()),
            'Delete this batch?',
            BatchSummary::delete($this->label(), array_map(fn (array $current): ?string => $this->nameOf($current), $deletes), $results),
            'Re-invoke with confirm: true to apply this deletion.',
        );
        if (null !== $refusal) {
            foreach (array_keys($deletes) as $key) {
                $results[$key] = ['ok' => false, 'action' => 'failed', 'reason' => $refusal['reason']];
            }
            $results = self::deleteInOrder($unique, $results);

            return ['ok' => false, 'total' => $total] + array_intersect_key($refusal, ['reason' => 1, 'hint' => 1]) + self::deleteCounts($results) + ['results' => $results];
        }

        $batchId = Uuid::v7()->toRfc4122();
        $paths = [];
        foreach ($deletes as $key => $current) {
            $paths[$key] = $this->deletePath($collection, (string) $key, $current);
        }
        $outcomes = $this->client->deleteMany($paths);
        foreach ($deletes as $key => $current) {
            $outcome = $outcomes[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
            if (true !== $outcome['ok']) {
                $results[$key] = $this->deleteFailure($outcome);

                continue;
            }
            $this->audit->stage($tool, $this->targetType(), (string) $key, $current, [], ['deleted' => ['before' => false, 'after' => true]], $context, batchId: $batchId);
            $results[$key] = ['ok' => true, 'action' => 'deleted'];
        }
        $this->audit->flush();

        return self::deleteEnvelope($batchId, self::deleteInOrder($unique, $results));
    }

    /**
     * @param array<string, true>                 $unique
     * @param array<string, array<string, mixed>> $results
     *
     * @return array<string, array<string, mixed>>
     */
    private static function deleteInOrder(array $unique, array $results): array
    {
        $ordered = [];
        foreach (array_keys($unique) as $key) {
            if (isset($results[$key])) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private static function deleteEnvelope(?string $batchId, array $results): array
    {
        return ['ok' => true] + (null === $batchId ? [] : ['batchId' => $batchId]) + ['total' => \count($results)] + self::deleteCounts($results) + ['results' => $results];
    }

    /**
     * @param array<string, array<string, mixed>> $results
     *
     * @return array{deleted: int, failed: int}
     */
    private static function deleteCounts(array $results): array
    {
        $counts = ['deleted' => 0, 'failed' => 0];
        foreach ($results as $result) {
            ++$counts['deleted' === ($result['action'] ?? null) ? 'deleted' : 'failed'];
        }

        return $counts;
    }
}
