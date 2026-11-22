<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Adapter;

use Emagma\McpSyliusBundle\Exception\UnsupportedRouteException;

/**
 * Builds paths from the templates the resources declare; the tables of a line name only what it does
 * differently. A parent a template does not use is ignored.
 */
abstract readonly class AbstractSyliusApiAdapter implements SyliusApiAdapterInterface
{
    /** @var array<string, string> resource => template of an item, its last placeholder the key */
    protected const array ITEMS = [];

    /** @var array<string, string> resource, or `resource METHOD`, => template of the collection where it is not the item's parent */
    protected const array COLLECTIONS = [];

    /** @var array<string, string> `resource action` => template where it is not the path followed by `/action` */
    protected const array ACTIONS = [];

    private string $iriPrefix;

    public function __construct(string $apiUrl)
    {
        $path = parse_url($apiUrl, \PHP_URL_PATH);
        $this->iriPrefix = '/' . trim(\is_string($path) ? $path : '', '/') . '/';
    }

    public function path(Route $route, string $method = 'GET'): string
    {
        $resource = $route->resource->routeKey();
        $item = static::ITEMS[$resource] ?? $route->resource->itemTemplate();
        $keyName = null === $item ? null : self::lastPlaceholder($item);

        if (null === $route->key) {
            $template = static::COLLECTIONS[$resource . ' ' . strtoupper($method)]
                ?? static::COLLECTIONS[$resource]
                ?? (\array_key_exists($resource, static::ITEMS) ? null : $route->resource->collectionTemplate())
                ?? (null === $item ? null : self::parentOf($item));
        } else {
            $template = $item;
        }
        if (null === $template) {
            throw new UnsupportedRouteException(\sprintf('%s has no %s route on this Sylius line.', $route->describe(), null === $route->key ? 'collection' : 'item'));
        }
        if (null !== $route->action) {
            $template = static::ACTIONS[$resource . ' ' . $route->action] ?? $template . '/' . $route->action;
        }

        $values = $route->parents;
        if (null !== $route->key && null !== $keyName) {
            $values[$keyName] = $route->key;
        }

        $path = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $placeholder) use ($values, $route): string {
            if (!\array_key_exists($placeholder[1], $values)) {
                throw new UnsupportedRouteException(\sprintf('%s: the route needs "%s".', $route->describe(), $placeholder[1]));
            }

            return rawurlencode((string) $values[$placeholder[1]]);
        }, $template);

        return [] === $route->query ? $path : $path . '?' . http_build_query($route->query);
    }

    public function iri(ApiResource $resource, string|int $key, array $parents = []): string
    {
        return $this->iriPrefix . $this->path(Route::item($resource, $key, $parents));
    }

    public function body(Route $route, string $method, array $body): array
    {
        return $body;
    }

    public function pathFromIri(string $iri): string
    {
        $position = strpos($iri, $this->iriPrefix);

        return false === $position ? $iri : substr($iri, $position + \strlen($this->iriPrefix));
    }

    private static function lastPlaceholder(string $template): ?string
    {
        return 1 === preg_match('/\{(\w+)\}$/', $template, $match) ? $match[1] : null;
    }

    private static function parentOf(string $template): string
    {
        return (string) preg_replace('#/\{\w+\}$#', '', $template);
    }
}
