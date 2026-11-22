<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared\Batch;

use Emagma\McpCoreBundle\Output\Field;
use Emagma\McpCoreBundle\Output\FieldRegistry;
use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Server\RequestContext;
use Symfony\Component\Uid\Uuid;

/**
 * A trait and not a service: the confirmation gate reads the tool's own
 * #[Permission], so WriteConfirmationTrait must be used by the same class.
 *
 * @internal
 */
trait BatchUpsertTrait
{
    private const int BATCH_MAX_ITEMS = 50;

    private const array BATCH_MODES = ['create', 'update', 'upsert'];

    /** The audit target type, e.g. `tax_category`. */
    abstract private function targetType(): string;

    /** What the human reads: `tax categories`. */
    abstract private function label(): string;

    /**
     * Null when the item is acceptable; its key is checked by the trait.
     *
     * @param array<mixed> $item
     */
    abstract private function validateItem(array $item): ?string;

    /**
     * `$current` is null for a creation; an empty diff means the item changes nothing.
     *
     * @param array<string, mixed>|null $current
     * @param array<mixed>              $item
     * @param mixed                     $references what {@see resolveReferences()} returned
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    abstract private function diffFor(?array $current, array $item, mixed $references): array;

    /**
     * @param array<mixed> $item
     *
     * @return array<string, mixed>
     */
    abstract private function postBody(array $item, mixed $references): array;

    /**
     * @param array<string, mixed> $current
     * @param array<mixed>         $item
     *
     * @return array<string, mixed>
     */
    abstract private function putBody(array $current, array $item, mixed $references): array;

    /**
     * The output DTO of a resource with fields about people: the `before` of a diff is a
     * stored value, rendered like any read (`#[Untrusted]` marked, `#[Identifier]` tokenised).
     *
     * @return class-string|null
     */
    private static function outputClass(): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $result a per-item result carrying `diff`
     *
     * @return array<string, mixed>
     */
    private static function withUntrustedBefore(array $result): array
    {
        $class = self::outputClass();
        $diff = $result['diff'] ?? null;
        if (null === $class || !\is_array($diff)) {
            return $result;
        }

        $untrusted = FieldRegistry::with($class, Field::Untrusted);
        $identifiers = PrivacyContext::hides() ? FieldRegistry::with($class, Field::Identifier) : [];
        $fields = [];
        $rendered = [];
        foreach ($diff as $key => $change) {
            // A dotted key (`en_US.name`) names the field first.
            $root = explode('.', (string) $key)[0];
            if (\is_array($change) && null !== ($change['before'] ?? null)) {
                if (\in_array($root, $identifiers, true) && \is_string($change['before'])) {
                    // A token is the server's, not the public's: tokenised, not marked.
                    $change['before'] = PrivacyContext::token($root, $change['before']);
                } elseif (\in_array($root, $untrusted, true)) {
                    $fields[] = 'diff.' . $key . '.before';
                }
            }
            $rendered[$key] = $change;
        }
        $result['diff'] = $rendered;

        return [] === $fields ? $result : $result + [Field::UNTRUSTED_CONTENT_KEY => ['fields' => $fields]];
    }

    /**
     * @param array<mixed> $item
     */
    private function keyOf(array $item): ?string
    {
        $key = $item['code'] ?? null;

        return \is_string($key) && '' !== trim($key) ? $key : null;
    }

    /**
     * Only a 404 means absent; anything else fails the item.
     *
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
     * A resource addressed by a database id overrides this and reads the id off `$current`.
     *
     * @param array<string, mixed> $current
     */
    private function writePath(Route $collection, string $key, array $current): Route
    {
        return $collection->withKey($key);
    }

    /**
     * The `targetCode` the audit records: the item key, unless the key is personal data (an email)
     * and the resource has an id of its own to be recorded by instead.
     *
     * @param array<string, mixed> $resource the resource as the shop returned it after the write
     */
    private function auditCode(string $key, array $resource): string
    {
        return $key;
    }

    /**
     * @param array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null} $outcome
     * @param array<string, array{before: mixed, after: mixed}>                                      $diff
     *
     * @return array<string, mixed>
     */
    private function writeFailure(array $outcome, array $diff): array
    {
        return self::withUntrustedBefore(ApiFailure::fromResult($outcome, ['action' => 'failed', 'diff' => $diff]));
    }

    /**
     * @param array<string, array{before: mixed, after: mixed}> $diff
     *
     * @return list<string>
     */
    private function previewLines(string $key, array $diff): array
    {
        return [];
    }

    /**
     * `failures`: key => the per-item result of an unknown reference; `references`: whatever
     * the body hooks need. `$probes` is what {@see probe()} answered.
     *
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: mixed}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        return ['failures' => [], 'references' => null];
    }

    /**
     * @param list<mixed> $items
     *
     * @return array<string, mixed>
     */
    private function runUpsert(string $tool, Route $collection, array $items, string $mode, bool $confirm, ?RequestContext $context): array
    {
        $total = \count($items);
        if (!\in_array($mode, self::BATCH_MODES, true)) {
            return ['ok' => false, 'total' => $total, 'reason' => 'invalid_mode', 'hint' => 'mode is one of create, update, upsert.'];
        }
        if (0 === $total) {
            return self::upsertEnvelope(null, []);
        }
        if ($total > self::BATCH_MAX_ITEMS) {
            return ['ok' => false, 'total' => $total, 'reason' => 'too_many_items', 'hint' => \sprintf('At most %d items per call; split the batch.', self::BATCH_MAX_ITEMS)];
        }

        // Refused as a whole before any HTTP: a malformed item is a caller mistake, not a shop answer.
        $byKey = [];
        $errors = [];
        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                $errors[] = ['index' => $index, 'message' => 'an item is an object'];

                continue;
            }
            $key = $this->keyOf($item);
            if (null === $key) {
                // A blank key would address the collection route, which answers 200 and reads as "exists".
                $errors[] = ['index' => $index, 'message' => 'the item key is required'];

                continue;
            }
            if (isset($byKey[$key])) {
                $errors[] = ['index' => $index, 'code' => $key, 'message' => 'the key appears twice in the batch'];

                continue;
            }
            $message = $this->validateItem($item);
            if (null !== $message) {
                $errors[] = ['index' => $index, 'code' => $key, 'message' => $message];

                continue;
            }
            $byKey[$key] = $item;
        }
        if ([] !== $errors) {
            return ['ok' => false, 'total' => $total, 'reason' => 'items_invalid', 'errors' => $errors];
        }

        $probes = $this->probe($collection, array_keys($byKey));
        $resolved = $this->resolveReferences($byKey, $probes);
        $results = $resolved['failures'];
        $references = $resolved['references'];

        $creates = [];
        $updates = [];
        foreach ($byKey as $key => $item) {
            $probe = $probes[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            $current = null;
            if (true === $probe['ok']) {
                $current = $probe['body'];
            } elseif (404 !== ($probe['status'] ?? null)) {
                $results[$key] = ApiFailure::fromResult($probe, ['action' => 'failed']);

                continue;
            }

            // The mode verdict comes first: it is what the caller acts on, whatever a hook found wrong with the item.
            if (null === $current && 'update' === $mode) {
                $results[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'not_found'];

                continue;
            }
            if (null !== $current && 'create' === $mode) {
                $results[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'already_exists'];

                continue;
            }
            if (isset($results[$key])) {
                continue; // a reference it names is unknown, or the hook refused it
            }

            if (null === $current) {
                $creates[$key] = ['diff' => $this->diffFor(null, $item, $references), 'body' => $this->postBody($item, $references)];

                continue;
            }
            $diff = $this->diffFor($current, $item, $references);
            if ([] === $diff) {
                $results[$key] = ['ok' => true, 'action' => 'unchanged'];

                continue;
            }
            $updates[$key] = ['diff' => $diff, 'body' => $this->putBody($current, $item, $references), 'current' => $current];
        }

        if ([] === $creates && [] === $updates) {
            return self::upsertEnvelope(null, self::inOrder($byKey, $results));
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            \sprintf('Confirm %d change(s) on %s', \count($creates) + \count($updates), $this->label()),
            'Apply this batch?',
            BatchSummary::upsert(
                $this->label(),
                array_map(static fn (array $planned): array => $planned['diff'], $creates),
                array_map(static fn (array $planned): array => $planned['diff'], $updates),
                $results,
                fn (string $key, array $diff): array => $this->previewLines($key, $diff),
            ),
            'Re-invoke with confirm: true to apply this batch.',
        );
        if (null !== $refusal) {
            foreach ($creates + $updates as $key => $planned) {
                $results[$key] = ['ok' => false, 'action' => 'failed', 'reason' => $refusal['reason'], 'diff' => $planned['diff']];
            }
            $results = self::inOrder($byKey, $results);

            return ['ok' => false, 'total' => $total] + array_intersect_key($refusal, ['reason' => 1, 'hint' => 1]) + self::counts($results) + ['results' => $results];
        }

        $batchId = Uuid::v7()->toRfc4122();
        $postRequests = [];
        foreach ($creates as $key => $planned) {
            $postRequests[$key] = ['path' => $collection, 'body' => $planned['body']];
        }
        $putRequests = [];
        foreach ($updates as $key => $planned) {
            $putRequests[$key] = ['path' => $this->writePath($collection, (string) $key, $planned['current']), 'body' => $planned['body']];
        }
        $posted = [] === $postRequests ? [] : $this->client->postMany($postRequests);
        $put = [] === $putRequests ? [] : $this->client->putMany($putRequests);

        foreach ($creates as $key => $planned) {
            $result = $posted[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
            if (true !== $result['ok']) {
                $results[$key] = $this->writeFailure($result, $planned['diff']);

                continue;
            }
            $this->audit->stage($tool, $this->targetType(), $this->auditCode((string) $key, $result['body']), [], $result['body'], $planned['diff'], $context, batchId: $batchId);
            $results[$key] = self::withUntrustedBefore(['ok' => true, 'action' => 'created', 'diff' => $planned['diff']]);
        }
        foreach ($updates as $key => $planned) {
            $result = $put[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
            if (true !== $result['ok']) {
                $results[$key] = $this->writeFailure($result, $planned['diff']);

                continue;
            }
            $this->audit->stage($tool, $this->targetType(), $this->auditCode((string) $key, $result['body'] + $planned['current']), $planned['current'], $result['body'], $planned['diff'], $context, batchId: $batchId);
            $results[$key] = self::withUntrustedBefore(['ok' => true, 'action' => 'updated', 'diff' => $planned['diff']]);
        }
        $this->audit->flush();

        return self::upsertEnvelope($batchId, self::inOrder($byKey, $results));
    }

    /**
     * The caller's order, not creates-then-updates.
     *
     * @param array<string, array<mixed>>         $byKey
     * @param array<string, array<string, mixed>> $results
     *
     * @return array<string, array<string, mixed>>
     */
    private static function inOrder(array $byKey, array $results): array
    {
        $ordered = [];
        foreach (array_keys($byKey) as $key) {
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
    private static function upsertEnvelope(?string $batchId, array $results): array
    {
        return ['ok' => true] + (null === $batchId ? [] : ['batchId' => $batchId]) + ['total' => \count($results)] + self::counts($results) + ['results' => $results];
    }

    /**
     * @param array<string, array<string, mixed>> $results
     *
     * @return array{created: int, updated: int, unchanged: int, failed: int}
     */
    private static function counts(array $results): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach ($results as $result) {
            $action = $result['action'] ?? 'failed';
            if (\is_string($action) && isset($counts[$action])) {
                ++$counts[$action];
            }
        }

        return $counts;
    }
}
