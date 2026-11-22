<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;

/**
 * Paths are relative to SYLIUS_API_URL: a {@see Route} goes through the adapter
 * of the shop's Sylius line, a string is taken as is.
 */
interface AdminApiClientInterface
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers Overrides of the scoped client's headers; an empty string suppresses one.
     */
    public function getRaw(string|Route $path, array $query = [], array $headers = []): string;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function getDecoded(string|Route $path, array $query = [], array $headers = []): array;

    /**
     * Concurrent, input order preserved; a failure is reported under its key, not raised.
     *
     * @param array<array-key, string|Route> $paths
     * @param array<string, string>    $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string}>
     */
    public function getDecodedMany(array $paths, array $headers = []): array;

    /**
     * Not partial: the body carries the full resource. A 422 propagates as a ClientException.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function put(string|Route $path, array $body, array $headers = []): array;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function post(string|Route $path, array $body, array $headers = []): array;

    /**
     * Sets the merge-patch content type itself (the only patch format Sylius registers): callers must not.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function patch(string|Route $path, array $body = [], array $headers = []): array;

    /**
     * Concurrent; a failure is reported under its key with the response body (the 422 violations).
     *
     * @param array<array-key, array{path: string|Route, body: array<string, mixed>}> $items
     * @param array<string, string>                                              $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    public function putMany(array $items, array $headers = []): array;

    /**
     * @param array<array-key, array{path: string|Route, body: array<string, mixed>}> $items
     * @param array<string, string>                                              $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    public function postMany(array $items, array $headers = []): array;

    /**
     * @param array<array-key, string|Route> $paths
     * @param array<string, string>    $headers
     *
     * @return array<array-key, array{ok: true}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    public function deleteMany(array $paths, array $headers = []): array;

    /**
     * @param array<string, string> $headers
     */
    public function delete(string|Route $path, array $headers = []): void;

    /**
     * @param array<string, scalar|\Symfony\Component\Mime\Part\DataPart> $parts
     * @param array<string, string>                                       $headers
     *
     * @return array<string, mixed>
     */
    public function postMultipart(string|Route $path, array $parts, array $headers = []): array;
}
