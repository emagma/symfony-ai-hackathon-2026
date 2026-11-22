<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Catalog;

use Emagma\SyliusAssistantTestChat\Catalog\Provider;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ProviderCatalog::class)]
final class ProviderCatalogTest extends TestCase
{
    public function testTheShippedProvidersAndHowEachReportsItsCache(): void
    {
        /** @var array{parameters: array{'chat.providers': array<string, array<string, string>>}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/chat.yaml');
        /** @var array<string, array{label: string, platform: string, api_key: string, cache_reporting: string, base_url?: string|null, default_model?: string|null}> $providers */
        $providers = $config['parameters']['chat.providers'];

        $catalog = new ProviderCatalog(array_map(static fn (array $p): array => ['api_key' => ''] + $p, $providers));

        self::assertSame(['openai', 'anthropic', 'mistral', 'qwen'], array_keys($catalog->all()));
        self::assertSame(Provider::CACHE_SEPARATE, $catalog->get('anthropic')->cacheReporting);
        self::assertSame(Provider::CACHE_INCLUSIVE, $catalog->get('openai')->cacheReporting);
        self::assertFalse($catalog->get('mistral')->hasApiKey);
    }

    public function testAnUnknownProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProviderCatalog([]))->get('gemini');
    }
}
