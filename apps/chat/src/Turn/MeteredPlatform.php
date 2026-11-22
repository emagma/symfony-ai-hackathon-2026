<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Turn;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Decorates each provider's platform (config/services.yaml) to meter every
 * model call: the agent's runner invokes the platform once per tool-loop
 * iteration, so this sees each call and its own token usage.
 *
 * Not the agent's final result: its usage aggregates the whole loop, and
 * adding it on top would count every call twice.
 */
final class MeteredPlatform implements PlatformInterface
{
    public function __construct(
        private readonly PlatformInterface $inner,
        private readonly string $provider,
        private readonly TurnMeter $meter,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $started = microtime(true);
        $result = $this->inner->invoke($model, $input, $options);
        $name = $model instanceof Model ? $model->getName() : explode('?', $model, 2)[0];

        $result->onConvert(function (ResultInterface $converted) use ($name, $started): ResultInterface {
            $usage = $converted->getMetadata()->get('token_usage');
            $this->meter->record($this->provider, $name, $usage instanceof TokenUsageInterface ? $usage : null, $started);

            return $converted;
        });

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
