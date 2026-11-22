<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Admin API client: authenticates with the service account, sends the requests of the adapter's layout and reads the answers.
 *
 * @internal
 */
final class AdminApiClient implements AdminApiClientInterface
{
    private const string PLUGIN_HEADER = 'sylius-mcp-plugin';

    /** The header of the plugin before it was renamed: a shop not yet updated is still recognised. */
    private const string LEGACY_PLUGIN_HEADER = 'sylius-mcp-companion';

    private bool $pluginChecked = false;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly JwtTokenProviderInterface $tokens,
        private readonly ?LoggerInterface $logger = null,
        private readonly SyliusApiAdapterInterface $adapter = new DefaultLayoutAdapter('/api/v2/admin/'),
    ) {
    }

    private function resolve(string|Route $path, string $method): string
    {
        return $path instanceof Route ? $this->adapter->path($path, $method) : $path;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function bodyFor(string|Route $path, string $method, array $body): array
    {
        return $path instanceof Route ? $this->adapter->body($path, $method, $body) : $body;
    }

    /**
     * @param array<array-key, string|Route> $paths
     *
     * @return array<array-key, string>
     */
    private function resolveAll(array $paths, string $method): array
    {
        return array_map(fn (string|Route $path): string => $this->resolve($path, $method), $paths);
    }

    public function getDecoded(string|Route $path, array $query = [], array $headers = []): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->getRaw($path, $query, $headers), associative: true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * An empty body is not a failure: the messenger-backed operations answer 202 with no content, after the write.
     *
     * @return array<string, mixed>
     */
    private static function decodeBody(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public function getRaw(string|Route $path, array $query = [], array $headers = []): string
    {
        $path = $this->resolve($path, 'GET');

        try {
            return $this->call($path, $query, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }

            $this->tokens->invalidate();

            return $this->call($path, $query, $headers);
        }
    }

    public function getDecodedMany(array $paths, array $headers = []): array
    {
        if ([] === $paths) {
            return [];
        }
        $paths = $this->resolveAll($paths, 'GET');

        $results = $this->dispatchMany($paths, $this->tokens->getToken(), $headers);

        $stale = array_filter(
            $results,
            static fn (array $r): bool => false === $r['ok'] && \in_array($r['status'], [401, 403], true),
        );
        if ([] !== $stale) {
            $this->tokens->invalidate();
            $retryPaths = array_intersect_key($paths, $stale);
            $retried = $this->dispatchMany($retryPaths, $this->tokens->getToken(), $headers);
            $results = array_replace($results, $retried);
        }

        return $results;
    }

    /**
     * @param array<array-key, string> $paths
     * @param array<string, string>    $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string}>
     */
    private function dispatchMany(array $paths, string $bearer, array $headers = []): array
    {
        $options = ['auth_bearer' => $bearer];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        /** @var array<array-key, ResponseInterface> $responses */
        $responses = [];
        foreach ($paths as $key => $path) {
            $responses[$key] = $this->http->request('GET', $path, $options);
        }

        $results = [];
        foreach ($this->http->stream($responses) as $response => $chunk) {
            $key = array_search($response, $responses, true);
            if (false === $key) {
                continue;
            }

            try {
                // isLast() throws on an ErrorChunk: inside the try, one failed request does not abort the batch.
                if ($chunk->isFirst()) {
                    // Touched now: stream() would throw on an untouched error status, outside this try.
                    $response->getStatusCode();
                }
                if (!$chunk->isLast()) {
                    continue;
                }
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($response->getContent(), associative: true, flags: \JSON_THROW_ON_ERROR);
                $results[$key] = ['ok' => true, 'body' => $decoded];
            } catch (HttpExceptionInterface $e) {
                $status = $e instanceof ClientException ? $e->getResponse()->getStatusCode() : null;
                $results[$key] = ['ok' => false, 'status' => $status, 'error' => $e->getMessage()];
            } catch (\JsonException $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => 'json_decode: ' . $e->getMessage()];
            } catch (\Throwable $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => $e->getMessage()];
            }
        }

        $ordered = [];
        foreach ($paths as $key => $_) {
            $ordered[$key] = $results[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
        }

        return $ordered;
    }

    public function put(string|Route $path, array $body, array $headers = []): array
    {
        $body = $this->bodyFor($path, 'PUT', $body);
        $path = $this->resolve($path, 'PUT');

        try {
            $raw = $this->putRaw($path, $body, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }

            $this->tokens->invalidate();
            $raw = $this->putRaw($path, $body, $headers);
        }

        return self::decodeBody($raw);
    }

    public function post(string|Route $path, array $body, array $headers = []): array
    {
        $body = $this->bodyFor($path, 'POST', $body);
        $path = $this->resolve($path, 'POST');

        try {
            $raw = $this->sendJson('POST', $path, $body, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }

            $this->tokens->invalidate();
            $raw = $this->sendJson('POST', $path, $body, $headers);
        }

        return self::decodeBody($raw);
    }

    public function patch(string|Route $path, array $body = [], array $headers = []): array
    {
        $path = $this->resolve($path, 'PATCH');

        // The only patch format Sylius registers; the scoped client defaults to ld+json.
        $headers['Content-Type'] = 'application/merge-patch+json';

        // An empty array would encode to `[]`; the action operations expect a JSON object.
        $payload = [] === $body ? new \ArrayObject() : $body;

        try {
            $raw = $this->sendJson('PATCH', $path, $payload, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }

            $this->tokens->invalidate();
            $raw = $this->sendJson('PATCH', $path, $payload, $headers);
        }

        return self::decodeBody($raw);
    }

    public function putMany(array $items, array $headers = []): array
    {
        return $this->bodyFanOut('PUT', $items, $headers);
    }

    public function postMany(array $items, array $headers = []): array
    {
        return $this->bodyFanOut('POST', $items, $headers);
    }

    public function deleteMany(array $paths, array $headers = []): array
    {
        if ([] === $paths) {
            return [];
        }
        $paths = $this->resolveAll($paths, 'DELETE');

        $results = $this->dispatchDeleteMany($paths, $this->tokens->getToken(), $headers);

        $stale = array_filter(
            $results,
            static fn (array $r): bool => false === $r['ok'] && \in_array($r['status'], [401, 403], true),
        );
        if ([] !== $stale) {
            $this->tokens->invalidate();
            $retryPaths = array_intersect_key($paths, $stale);
            $retried = $this->dispatchDeleteMany($retryPaths, $this->tokens->getToken(), $headers);
            $results = array_replace($results, $retried);
        }

        return $results;
    }

    /**
     * @param array<array-key, array{path: string|Route, body: array<string, mixed>}> $requested
     * @param array<string, string>                                                    $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    private function bodyFanOut(string $method, array $requested, array $headers): array
    {
        if ([] === $requested) {
            return [];
        }
        $items = [];
        foreach ($requested as $key => $item) {
            $items[$key] = ['path' => $this->resolve($item['path'], $method), 'body' => $this->bodyFor($item['path'], $method, $item['body'])];
        }

        $results = $this->dispatchBodyMany($method, $items, $this->tokens->getToken(), $headers);

        $stale = array_filter(
            $results,
            static fn (array $r): bool => false === $r['ok'] && \in_array($r['status'], [401, 403], true),
        );
        if ([] !== $stale) {
            $this->tokens->invalidate();
            $retryItems = array_intersect_key($items, $stale);
            $retried = $this->dispatchBodyMany($method, $retryItems, $this->tokens->getToken(), $headers);
            $results = array_replace($results, $retried);
        }

        return $results;
    }

    /**
     * @param array<array-key, array{path: string, body: array<string, mixed>}> $items
     * @param array<string, string>                                              $headers
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    private function dispatchBodyMany(string $method, array $items, string $bearer, array $headers): array
    {
        $baseOptions = ['auth_bearer' => $bearer];
        if ([] !== $headers) {
            $baseOptions['headers'] = $headers;
        }

        /** @var array<array-key, ResponseInterface> $responses */
        $responses = [];
        foreach ($items as $key => $item) {
            $responses[$key] = $this->http->request($method, $item['path'], $baseOptions + ['json' => $item['body']]);
        }

        $results = [];
        foreach ($this->http->stream($responses) as $response => $chunk) {
            $key = array_search($response, $responses, true);
            if (false === $key) {
                continue;
            }

            try {
                if ($chunk->isFirst()) {
                    $response->getStatusCode();
                }
                if (!$chunk->isLast()) {
                    continue;
                }
                $status = $response->getStatusCode();
                $content = $response->getContent(throw: false);
                /** @var array<string, mixed>|null $decoded */
                $decoded = '' === $content
                    ? null
                    : json_decode($content, associative: true, flags: \JSON_THROW_ON_ERROR);

                if ($status >= 200 && $status < 300) {
                    $results[$key] = ['ok' => true, 'body' => \is_array($decoded) ? $decoded : []];
                } else {
                    $results[$key] = [
                        'ok' => false,
                        'status' => $status,
                        'error' => \sprintf('HTTP %d', $status),
                        'body' => \is_array($decoded) ? $decoded : null,
                    ];
                }
            } catch (HttpExceptionInterface $e) {
                $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : null;
                $results[$key] = ['ok' => false, 'status' => $status, 'error' => $e->getMessage(), 'body' => null];
            } catch (\JsonException $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => 'json_decode: ' . $e->getMessage(), 'body' => null];
            } catch (\Throwable $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'body' => null];
            }
        }

        $ordered = [];
        foreach ($items as $key => $_) {
            $ordered[$key] = $results[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
        }

        return $ordered;
    }

    /**
     * @param array<array-key, string> $paths
     * @param array<string, string>    $headers
     *
     * @return array<array-key, array{ok: true}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    private function dispatchDeleteMany(array $paths, string $bearer, array $headers): array
    {
        $baseOptions = ['auth_bearer' => $bearer];
        if ([] !== $headers) {
            $baseOptions['headers'] = $headers;
        }

        /** @var array<array-key, ResponseInterface> $responses */
        $responses = [];
        foreach ($paths as $key => $path) {
            $responses[$key] = $this->http->request('DELETE', $path, $baseOptions);
        }

        $results = [];
        foreach ($this->http->stream($responses) as $response => $chunk) {
            $key = array_search($response, $responses, true);
            if (false === $key) {
                continue;
            }

            try {
                if ($chunk->isFirst()) {
                    $response->getStatusCode();
                }
                if (!$chunk->isLast()) {
                    continue;
                }
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $results[$key] = ['ok' => true];

                    continue;
                }
                $content = $response->getContent(throw: false);
                /** @var array<string, mixed>|null $decoded */
                $decoded = '' === $content
                    ? null
                    : json_decode($content, associative: true, flags: \JSON_THROW_ON_ERROR);
                $results[$key] = [
                    'ok' => false,
                    'status' => $status,
                    'error' => \sprintf('HTTP %d', $status),
                    'body' => \is_array($decoded) ? $decoded : null,
                ];
            } catch (HttpExceptionInterface $e) {
                $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : null;
                $results[$key] = ['ok' => false, 'status' => $status, 'error' => $e->getMessage(), 'body' => null];
            } catch (\JsonException $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => 'json_decode: ' . $e->getMessage(), 'body' => null];
            } catch (\Throwable $e) {
                $results[$key] = ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'body' => null];
            }
        }

        $ordered = [];
        foreach ($paths as $key => $_) {
            $ordered[$key] = $results[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    private function call(string $path, array $query, array $headers = []): string
    {
        $options = [
            'query' => $query,
            'auth_bearer' => $this->tokens->getToken(),
        ];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        $response = $this->http->request('GET', $path, $options);
        $content = $response->getContent();
        $this->checkPlugin($response);

        return $content;
    }

    private function checkPlugin(ResponseInterface $response): void
    {
        if ($this->pluginChecked) {
            return;
        }
        $this->pluginChecked = true;

        $headers = $response->getHeaders(false);
        if (!isset($headers[self::PLUGIN_HEADER]) && !isset($headers[self::LEGACY_PLUGIN_HEADER])) {
            $this->logger?->warning(
                'The shop answered without the Sylius-Mcp-Plugin header: the plugin is not installed,'
                . ' so payloads are not lean and the MCP-only filters (price range, order state, ...) are ignored.',
            );
        }
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function putRaw(string $path, array $body, array $headers): string
    {
        return $this->sendJson('PUT', $path, $body, $headers);
    }

    public function postMultipart(string|Route $path, array $parts, array $headers = []): array
    {
        $path = $this->resolve($path, 'POST');

        try {
            $raw = $this->sendMultipart('POST', $path, $parts, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();
            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }
            $this->tokens->invalidate();
            $raw = $this->sendMultipart('POST', $path, $parts, $headers);
        }

        return self::decodeBody($raw);
    }

    /**
     * @param array<string, scalar|\Symfony\Component\Mime\Part\DataPart> $parts
     * @param array<string, string>                                       $headers
     */
    private function sendMultipart(string $method, string $path, array $parts, array $headers): string
    {
        /** @var array<string, string|array<mixed>|\Symfony\Component\Mime\Part\TextPart> $fields */
        $fields = [];
        foreach ($parts as $key => $value) {
            $fields[$key] = is_scalar($value) ? (string) $value : $value;
        }
        $formData = new FormDataPart($fields);
        $preparedHeaders = [];
        foreach ($formData->getPreparedHeaders()->all() as $header) {
            $preparedHeaders[$header->getName()] = $header->getBodyAsString();
        }
        $perRequest = $headers + $preparedHeaders;
        // Never the caller's: the content type must carry the boundary.
        $perRequest['Content-Type'] = $preparedHeaders['Content-Type'] ?? 'multipart/form-data';

        $options = [
            'body' => $formData->bodyToString(),
            'auth_bearer' => $this->tokens->getToken(),
            'headers' => $perRequest,
        ];

        return $this->http->request($method, $path, $options)->getContent();
    }

    public function delete(string|Route $path, array $headers = []): void
    {
        $path = $this->resolve($path, 'DELETE');

        try {
            $this->sendNoBody('DELETE', $path, $headers);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();
            if (401 !== $status && 403 !== $status) {
                throw $exception;
            }
            $this->tokens->invalidate();
            $this->sendNoBody('DELETE', $path, $headers);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendNoBody(string $method, string $path, array $headers): void
    {
        $options = ['auth_bearer' => $this->tokens->getToken()];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        // getHeaders() triggers the transfer and throws on a non-2xx status.
        $this->http->request($method, $path, $options)->getHeaders();
    }

    /**
     * @param array<string, mixed>|\ArrayObject<string, mixed> $body
     * @param array<string, string>                            $headers
     */
    private function sendJson(string $method, string $path, array|\ArrayObject $body, array $headers): string
    {
        $options = [
            'json' => $body,
            'auth_bearer' => $this->tokens->getToken(),
        ];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        return $this->http->request($method, $path, $options)->getContent();
    }
}
