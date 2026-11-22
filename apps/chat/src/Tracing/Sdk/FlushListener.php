<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tracing\Sdk;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exports the request's spans once the response is out.
 *
 * kernel.terminate fires after Response::send() - for a streamed chat turn
 * (SSE), after the stream callback returned, elicitation waits included - so
 * the export never delays what the client sees.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'flush', priority: -1024)]
#[AsEventListener(event: 'console.terminate', method: 'flush', priority: -1024)]
final class FlushListener
{
    public function __construct(
        #[Autowire(service: 'chat.tracer_provider')]
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    public function flush(): void
    {
        if (method_exists($this->tracerProvider, 'forceFlush')) {
            $this->tracerProvider->forceFlush();
        }
    }
}
