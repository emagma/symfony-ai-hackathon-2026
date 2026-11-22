<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpCoreBundle\Exception\RevertRefusedException;
use Emagma\McpCoreBundle\Journal\RevertTargetInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * A snapshot is addressed by its own `@id`. Writing one back does not undo a
 * state-machine transition: `state` is outside Sylius' update groups.
 *
 * @internal
 */
final readonly class AdminApiRevertTarget implements RevertTargetInterface
{
    public function __construct(
        private AdminApiClientInterface $client,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * Null for an IRI outside the Admin API root: not ours to write to.
     */
    public function locate(array $snapshot): ?string
    {
        $iri = $snapshot['@id'] ?? null;
        if (!\is_string($iri)) {
            return null;
        }

        $path = $this->adapter->pathFromIri($iri);

        return '' === $path || $path === $iri ? null : $path;
    }

    public function read(string $address): array
    {
        try {
            return $this->client->getDecoded($address);
        } catch (ClientException $exception) {
            throw self::refused($exception);
        }
    }

    public function write(string $address, array $snapshot): array
    {
        try {
            return $this->client->put($address, $snapshot);
        } catch (ClientException $exception) {
            throw self::refused($exception);
        }
    }

    /**
     * Metadata, not state: `updatedAt` alone would make every revert read as incomplete.
     *
     * @var list<string>
     */
    private const array IGNORED_FIELDS = ['@id', '@type', '@context', 'id', 'createdAt', 'updatedAt'];

    /** The path API Platform gives an embedded object it has no IRI for. */
    private const string BLANK_NODE = '/.well-known/genid/';

    /**
     * Translations are flattened per locale (`fr_FR.name`).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function diff(array $current, array $snapshot): array
    {
        $diff = [];

        foreach (self::comparableKeys($current, $snapshot) as $key) {
            if ('translations' === $key) {
                continue;
            }
            $before = $current[$key] ?? null;
            $after = $snapshot[$key] ?? null;
            if (!self::same($before, $after)) {
                $diff[$key] = ['before' => $before, 'after' => $after];
            }
        }

        $currentT = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $targetT = \is_array($snapshot['translations'] ?? null) ? $snapshot['translations'] : [];

        foreach (self::comparableKeys($currentT, $targetT) as $locale) {
            $before = \is_array($currentT[$locale] ?? null) ? $currentT[$locale] : [];
            $after = \is_array($targetT[$locale] ?? null) ? $targetT[$locale] : [];

            foreach (self::comparableKeys($before, $after) as $field) {
                if (!self::same($before[$field] ?? null, $after[$field] ?? null)) {
                    $diff[$locale . '.' . $field] = [
                        'before' => $before[$field] ?? null,
                        'after' => $after[$field] ?? null,
                    ];
                }
            }
        }

        return $diff;
    }

    /**
     * An embedded object gets a fresh blank-node `@id` and an arbitrary key order on every read: compared
     * strictly, a resource never equals its own snapshot. A real IRI and the order of a list stay significant.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        unset($value['@type'], $value['@context']);
        if (\is_string($value['@id'] ?? null) && str_contains($value['@id'], self::BLANK_NODE)) {
            unset($value['@id']);
        }
        ksort($value);

        return array_map(self::canonical(...), $value);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<string>
     */
    private static function comparableKeys(array $a, array $b): array
    {
        $keys = array_unique([...array_keys($a), ...array_keys($b)]);

        return array_values(array_filter(
            $keys,
            static fn (mixed $key): bool => \is_string($key) && !\in_array($key, self::IGNORED_FIELDS, true),
        ));
    }

    public function readable(mixed $value): mixed
    {
        return self::canonical($value);
    }

    private static function refused(ClientException $exception): RevertRefusedException
    {
        $failure = ApiFailure::describe($exception);
        unset($failure['ok']);

        return new RevertRefusedException(404 === $exception->getResponse()->getStatusCode(), $failure, $exception);
    }
}
