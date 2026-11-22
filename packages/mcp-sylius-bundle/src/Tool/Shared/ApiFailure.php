<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

use Emagma\McpSyliusBundle\AdminApi\ViolationExtractor;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The one mapping of an Admin API refusal for the write tools. `upstream_error` carries the status
 * and nothing of the response body: an internal URL or a Symfony error page is not for the model.
 *
 * @internal
 */
final class ApiFailure
{
    private function __construct()
    {
    }

    /**
     * `$notFound` names the 404 when the request was not about the tool's own target
     * (a coupon tool reading its promotion answers `promotion_not_found`).
     *
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    public static function describe(ClientException $exception, array $base = [], string $notFound = 'not_found'): array
    {
        $status = $exception->getResponse()->getStatusCode();

        return ['ok' => false] + $base + match ($status) {
            404 => ['reason' => $notFound],
            409 => ['reason' => 'conflict', 'errors' => ViolationExtractor::fromException($exception)],
            422 => ['reason' => 'validation_error', 'errors' => ViolationExtractor::fromException($exception)],
            default => ['reason' => 'upstream_error', 'status' => $status],
        };
    }

    /**
     * For one failed item of a fan-out, which does not throw. A missing status
     * (the request never got an answer) is an upstream error too.
     *
     * @param array{status?: int|null, body?: array<string, mixed>|null} $result
     * @param array<string, mixed>                                        $base
     *
     * @return array<string, mixed>
     */
    public static function fromResult(array $result, array $base = [], string $notFound = 'not_found'): array
    {
        $status = $result['status'] ?? null;
        $body = $result['body'] ?? null;

        return ['ok' => false] + $base + match ($status) {
            404 => ['reason' => $notFound],
            409 => ['reason' => 'conflict', 'errors' => ViolationExtractor::fromPayload($body)],
            422 => ['reason' => 'validation_error', 'errors' => ViolationExtractor::fromPayload($body)],
            default => ['reason' => 'upstream_error', 'status' => $status],
        };
    }
}
