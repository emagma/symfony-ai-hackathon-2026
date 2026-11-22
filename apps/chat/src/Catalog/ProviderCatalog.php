<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Catalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The four providers (config/packages/chat.yaml). Their models come from
 * their own API (ProviderModels), their prices from Langfuse (PriceBook).
 */
final class ProviderCatalog
{
    /** @var array<string, Provider> */
    private readonly array $providers;

    /**
     * @param array<string, array{label: string, platform: string, api_key: string, cache_reporting: string, base_url?: string|null, default_model?: string|null}> $providers
     */
    public function __construct(
        #[Autowire('%chat.providers%')]
        array $providers,
    ) {
        $catalog = [];
        foreach ($providers as $key => $provider) {
            $catalog[$key] = new Provider($key, $provider['label'], $provider['platform'], $provider['api_key'], $provider['cache_reporting'], $provider['base_url'] ?? null, $provider['default_model'] ?? null);
        }
        $this->providers = $catalog;
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function get(string $provider): Provider
    {
        return $this->providers[$provider] ?? throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $provider));
    }
}
