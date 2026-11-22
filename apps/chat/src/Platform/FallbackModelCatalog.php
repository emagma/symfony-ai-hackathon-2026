<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Platform;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Lets a bridge take any model its API lists, not only those its catalog
 * names: the chat offers every chat model the provider returns, and Symfony
 * AI's catalogs lag behind the providers' releases.
 *
 * A known model keeps its own class and capabilities; an unknown one is built
 * as a chat model of the bridge's model class, able to call tools.
 */
final class FallbackModelCatalog extends AbstractModelCatalog
{
    private const CAPABILITIES = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STREAMING, Capability::TOOL_CALLING];

    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(
        private readonly ModelCatalogInterface $inner,
        private readonly string $modelClass,
    ) {
        $this->models = [];
    }

    public function getModel(string $modelName): Model
    {
        try {
            return $this->inner->getModel($modelName);
        } catch (ModelNotFoundException) {
            $parsed = $this->parseModelName($modelName);
            if ('' === $parsed['name']) {
                throw new ModelNotFoundException('Model name cannot be empty.');
            }

            return new ($this->modelClass)($parsed['name'], self::CAPABILITIES, $parsed['options']);
        }
    }

    /**
     * @return array<string, array{class: class-string, capabilities: list<Capability>}>
     */
    public function getModels(): array
    {
        /** @var array<string, array{class: class-string, capabilities: list<Capability>}> $models */
        $models = $this->inner->getModels();

        return $models;
    }
}
