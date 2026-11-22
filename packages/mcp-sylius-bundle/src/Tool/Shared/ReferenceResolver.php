<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

use Emagma\McpSyliusBundle\AdminApi\Adapter\ApiResource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Turns codes into the IRIs the Admin API wants (the shop's own `@id`): it answers an
 * opaque 422 to a code that does not resolve. Lookups are keyed by {@see key()}.
 *
 * @internal
 */
final readonly class ReferenceResolver
{
    /** @var array<string, ApiResource> route key => resource, for the ones Sylius' own enum does not hold */
    private array $resources;

    /**
     * @param iterable<ApiResource> $resources the resources of a shop extension, whose lookup keys this
     *                                         resolver is then able to read back
     */
    public function __construct(
        private AdminApiClientInterface $client,
        iterable $resources = [],
    ) {
        $byKey = [];
        foreach ($resources as $resource) {
            $byKey[$resource->routeKey()] = $resource;
        }
        $this->resources = $byKey;
    }

    /**
     * `taxon`, or with parents `product_option_value?option=size`.
     *
     * @param array<string, string> $parents
     */
    public static function key(ApiResource $resource, array $parents = []): string
    {
        ksort($parents);

        return $resource->routeKey() . ([] === $parents ? '' : '?' . http_build_query($parents));
    }

    /**
     * Null on a 404 only; any other failure propagates: a dead shop is not a missing taxon.
     *
     * @param array<string, string> $parents
     */
    public function iri(ApiResource $resource, string $code, array $parents = []): ?string
    {
        try {
            $payload = $this->client->getDecoded(Route::item($resource, $code, $parents));
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return null;
            }

            throw $exception;
        }

        return self::iriOf($payload);
    }

    /**
     * @param array<string, list<string>> $codesByKey {@see key()} => codes, e.g. ['channel' => ['WEB'], 'taxon' => ['dresses']]
     */
    public function resolve(array $codesByKey): ResolvedReferences
    {
        $known = $this->lookupAll($codesByKey);
        foreach ($codesByKey as $key => $codes) {
            foreach ($codes as $code) {
                if (!isset($known[$key][$code])) {
                    // The first missing code is enough to refuse: the model fixes one at a time anyway.
                    return new ResolvedReferences([], new MissingReference($key, $code));
                }
            }
        }

        return new ResolvedReferences($known, null);
    }

    /**
     * Lenient: silent about unknown codes, for a bulk tool that reports them item by item.
     *
     * @param array<string, list<string>> $codesByKey {@see key()} => codes
     *
     * @return array<string, array<string, string>> key => (code => IRI), only for the codes that exist
     */
    public function lookupAll(array $codesByKey): array
    {
        $routes = [];
        foreach ($codesByKey as $key => $codes) {
            [$resource, $parents] = $this->parse($key);
            foreach (array_unique($codes) as $code) {
                $routes[$key . "\0" . $code] = Route::item($resource, $code, $parents);
            }
        }
        if ([] === $routes) {
            return [];
        }

        $results = $this->client->getDecodedMany($routes);

        $iris = [];
        foreach (array_keys($routes) as $fanOutKey) {
            [$key, $code] = explode("\0", $fanOutKey, 2);
            $result = $results[$fanOutKey] ?? ['ok' => false];
            $iri = true === $result['ok'] ? self::iriOf($result['body']) : null;
            if (null !== $iri) {
                $iris[$key][$code] = $iri;
            }
        }

        return $iris;
    }

    /**
     * @return array{ApiResource, array<string, string>}
     */
    private function parse(string $key): array
    {
        [$resource, $query] = explode('?', $key, 2) + [1 => ''];
        parse_str($query, $parsed);
        /** @var array<string, string> $parents */
        $parents = $parsed;

        return [$this->resources[$resource] ?? Resource::from($resource), $parents];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function iriOf(array $payload): ?string
    {
        $iri = $payload['@id'] ?? null;

        return \is_string($iri) && '' !== $iri ? $iri : null;
    }
}
