<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Adapter;

use Emagma\McpSyliusBundle\Exception\UnsupportedRouteException;

/**
 * The Admin API of one Sylius line: one implementation per line whose routes differ.
 */
interface SyliusApiAdapterInterface
{
    /**
     * Relative to SYLIUS_API_URL, placeholders URL-encoded; the method matters where a collection is read and written at different paths.
     *
     * @throws UnsupportedRouteException when the line has no such route, or a placeholder is not given
     */
    public function path(Route $route, string $method = 'GET'): string;

    /**
     * The prefix is the path of SYLIUS_API_URL, never a constant: a shop may be mounted under a sub-path.
     *
     * @param array<string, string|int> $parents
     *
     * @throws UnsupportedRouteException
     */
    public function iri(ApiResource $resource, string|int $key, array $parents = []): string;

    /**
     * An IRI outside the Admin API is returned as is.
     */
    public function pathFromIri(string $iri): string;

    /**
     * What the URI of one line says, the body of another may have to (1.14: a coupon's promotion).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function body(Route $route, string $method, array $body): array;
}
