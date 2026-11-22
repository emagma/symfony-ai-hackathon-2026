<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Controller;

use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderModels;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every chat model each provider's API lists, with its Langfuse price per
 * million tokens when Langfuse knows it.
 */
final class CatalogController
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderModels $models,
        private readonly PriceBook $prices,
    ) {
    }

    #[Route('/api/catalog', name: 'catalog', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $providers = [];
        foreach ($this->catalog->all() as $provider) {
            $error = null;

            try {
                $models = $this->models->list($provider);
            } catch (\Throwable $e) {
                $models = [];
                $error = $e->getMessage();
            }

            $providers[] = [
                'key' => $provider->key,
                'label' => $provider->label,
                'available' => $provider->hasApiKey && [] !== $models,
                'error' => $provider->hasApiKey ? $error : 'No API key.',
                'default_model' => \in_array($provider->defaultModel, $models, true) ? $provider->defaultModel : null,
                'models' => array_map(fn (string $model): array => ['name' => $model, 'prices' => self::perMillion($this->prices->prices($model))], $models),
            ];
        }

        return new JsonResponse(['providers' => $providers]);
    }

    /**
     * @param array<string, float>|null $prices per token
     *
     * @return array<string, float>|null per million tokens
     */
    private static function perMillion(?array $prices): ?array
    {
        return null === $prices ? null : array_map(static fn (float $price): float => round($price * 1_000_000, 6), $prices);
    }
}
