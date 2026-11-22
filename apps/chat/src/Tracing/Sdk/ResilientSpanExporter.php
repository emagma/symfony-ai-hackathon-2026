<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tracing\Sdk;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Never throws and, after a failed export, drops spans during a cooldown so that an unreachable
 * collector costs at most one client timeout per cooldown window (not one per request / message).
 */
final class ResilientSpanExporter implements SpanExporterInterface
{
    private float $suspendedUntil = 0.0;

    public function __construct(
        private readonly SpanExporterInterface $inner,
        private readonly float $cooldown = 30.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->suspendedUntil > microtime(true)) {
            return new CompletedFuture(false);
        }

        try {
            $ok = (bool) $this->inner->export($batch, $cancellation)->await();
        } catch (Throwable $e) {
            $ok = false;
            $this->logger?->warning('Trace export failed: {message}', ['message' => $e->getMessage()]);
        }

        if (!$ok) {
            $this->suspendedUntil = microtime(true) + $this->cooldown;
            $this->logger?->warning('Trace export failed, exports suspended for {seconds}s.', ['seconds' => $this->cooldown]);
        }

        return new CompletedFuture($ok);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->shutdown($cancellation);
        } catch (Throwable) {
            return false;
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->forceFlush($cancellation);
        } catch (Throwable) {
            return false;
        }
    }
}
