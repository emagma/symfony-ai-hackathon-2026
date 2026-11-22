<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Adapter;

use Emagma\McpSyliusBundle\Exception\UnsupportedRouteException;

/**
 * Where a call goes, without the path: the adapter of the shop's Sylius line builds it.
 * `$key` fills the last placeholder of the resource's template, `$parents` the others, by name.
 */
final readonly class Route implements \Stringable
{
    /**
     * @param array<string, string|int> $parents
     * @param array<string, mixed>      $query
     */
    private function __construct(
        public ApiResource $resource,
        public string|int|null $key,
        public array $parents,
        public ?string $action,
        public array $query = [],
    ) {
    }

    /**
     * @param array<string, string|int> $parents
     */
    public static function collection(ApiResource $resource, array $parents = []): self
    {
        return new self($resource, null, $parents, null);
    }

    /**
     * @param array<string, string|int> $parents
     */
    public static function item(ApiResource $resource, string|int $key, array $parents = []): self
    {
        return new self($resource, $key, $parents, null);
    }

    /**
     * @param array<string, string|int> $parents
     */
    public static function action(ApiResource $resource, string|int $key, string $action, array $parents = []): self
    {
        return new self($resource, $key, $parents, $action);
    }

    /**
     * @param array<string, string|int> $parents
     */
    public static function collectionAction(ApiResource $resource, string $action, array $parents = []): self
    {
        return new self($resource, null, $parents, $action);
    }

    public function withKey(string|int $key): self
    {
        return new self($this->resource, $key, $this->parents, null);
    }

    /**
     * For the fan-outs, whose requests take a path only.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        return new self($this->resource, $this->key, $this->parents, $this->action, $query);
    }

    /**
     * The path in the default layout, for logs and tests only: a request asks the adapter of the shop's line.
     */
    public function __toString(): string
    {
        /** @var DefaultLayoutAdapter|null $layout */
        static $layout = null;
        $layout ??= new DefaultLayoutAdapter('/');

        try {
            return $layout->path($this);
        } catch (UnsupportedRouteException) {
            return $this->describe();
        }
    }

    public function describe(): string
    {
        return $this->resource->routeKey()
            . (null === $this->key ? '' : '#' . $this->key)
            . ([] === $this->parents ? '' : (string) json_encode($this->parents))
            . (null === $this->action ? '' : '/' . $this->action);
    }
}
