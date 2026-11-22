<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tracing\Sdk;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\Detectors\Environment;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the plugin's own TracerProvider - never the global one, so the host's
 * instrumentation (if any) is left alone.
 *
 * Configured by the standard OpenTelemetry variables: OTEL_EXPORTER_OTLP_ENDPOINT
 * (or OTEL_EXPORTER_OTLP_TRACES_ENDPOINT), OTEL_EXPORTER_OTLP_PROTOCOL,
 * OTEL_SERVICE_NAME, OTEL_RESOURCE_ATTRIBUTES. Without an endpoint, or on any
 * failure, it degrades to a no-op provider: tracing must never break or block
 * the MCP server.
 *
 * Exporting is synchronous in PHP and happens in the request that flushes, so
 * the budget is tight: a 1 s timeout, no retry, and a cooldown after a failure
 * so an unreachable collector costs one timeout per window, not one per request.
 */
final class TracerProviderFactory
{
    public static function create(float $timeout = 1.0, float $failureCooldown = 30.0, ?LoggerInterface $logger = null): TracerProviderInterface
    {
        // OTel's internal errors go to the application log, not to stderr.
        null === $logger ? LoggerHolder::disable() : LoggerHolder::set($logger);

        $endpoint = self::endpoint();
        if (null === $endpoint) {
            return new NoopTracerProvider();
        }

        try {
            $contentType = match (self::env('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL') ?? self::env('OTEL_EXPORTER_OTLP_PROTOCOL') ?? 'http/protobuf') {
                'http/protobuf' => 'application/x-protobuf',
                'http/json' => 'application/json',
                default => throw new \InvalidArgumentException('Only the http/protobuf and http/json OTLP protocols are supported.'),
            };

            $client = new Psr18Client(HttpClient::create(['timeout' => $timeout, 'max_duration' => $timeout * 1.5]));
            $transport = (new PsrTransportFactory($client, $client, $client))
                ->create($endpoint, $contentType, [], null, $timeout, 100, 0);

            $processor = new BatchSpanProcessor(
                new ResilientSpanExporter(new SpanExporter($transport), $failureCooldown, $logger),
                Clock::getDefault(),
                maxExportBatchSize: 512,
                // true would export synchronously from Span::end(), mid-request.
                // FlushListener flushes once the response is sent instead.
                autoFlush: false,
            );

            $provider = TracerProvider::builder()
                ->addSpanProcessor($processor)
                // Not the default resource: its host/process/SDK attributes land
                // in every Langfuse observation's metadata and say nothing about MCP.
                ->setResource((new Environment())->getResource()->merge(ResourceInfo::create(Attributes::create([
                    ResourceAttributes::SERVICE_NAME => self::env('OTEL_SERVICE_NAME') ?? 'sylius-assistant-testchat',
                ]))))
                ->build();

            // Spans still queued when the process ends would otherwise be lost.
            ShutdownHandler::register($provider->shutdown(...));

            return $provider;
        } catch (\Throwable $e) {
            $logger?->warning('TestChat tracing disabled: {message}', ['message' => $e->getMessage()]);

            return new NoopTracerProvider();
        }
    }

    private static function endpoint(): ?string
    {
        $signal = self::env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        if (null !== $signal) {
            return $signal;
        }

        $base = self::env('OTEL_EXPORTER_OTLP_ENDPOINT');

        return null === $base ? null : rtrim($base, '/') . '/v1/traces';
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
