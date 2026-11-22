<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Not every 422 lists violations: a domain rule failing in a service answers a plain `detail` or
 * `message`, read too so that a failed request never yields an empty list.
 *
 * @internal
 */
final class ViolationExtractor
{
    private function __construct()
    {
    }

    /**
     * @return list<array{property: string, message: string}>
     */
    public static function fromException(ClientException $exception): array
    {
        try {
            $payload = $exception->getResponse()->toArray(throw: false);
        } catch (\Throwable) {
            return [['property' => '', 'message' => $exception->getMessage()]];
        }

        return self::fromPayload($payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return list<array{property: string, message: string}>
     */
    public static function fromPayload(?array $payload): array
    {
        $violations = $payload['violations'] ?? $payload['hydra:violations'] ?? [];
        $out = [];
        if (\is_array($violations)) {
            foreach ($violations as $violation) {
                if (!\is_array($violation)) {
                    continue;
                }
                $out[] = [
                    'property' => (string) ($violation['propertyPath'] ?? ''),
                    'message' => (string) ($violation['message'] ?? ''),
                ];
            }
        }

        if ([] !== $out) {
            return $out;
        }

        foreach (['detail', 'hydra:description', 'description', 'message'] as $key) {
            $message = $payload[$key] ?? null;
            if (\is_string($message) && '' !== $message) {
                return [['property' => '', 'message' => $message]];
            }
        }

        return [];
    }
}
