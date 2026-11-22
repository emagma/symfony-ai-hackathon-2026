<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Catalog;

use Emagma\SyliusAssistantTestChat\Catalog\LangfusePriceDeclarer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(LangfusePriceDeclarer::class)]
final class LangfusePriceDeclarerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'models') . '.yaml';
        file_put_contents($this->file, <<<'YAML'
            models:
                mistral-small:
                    match: '(mistral-small-(latest|2603))'
                    prices: { input: 0.15, output: 0.60 }
                qwen-flash:
                    match: '(qwen-flash)'
                    prices: { input: 0.03, output: 0.13 }
            YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * Only the missing models are posted, with the prefix-tolerant pattern and
     * the per-token prices Langfuse stores.
     */
    public function testOnlyMissingModelsAreDeclared(): void
    {
        /** @var list<array{modelName: string, matchPattern: string, pricingTiers: list<array{isDefault: bool, prices: array<string, float>}>}> $posted */
        $posted = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$posted): JsonMockResponse {
            if ('GET' === $method) {
                return new JsonMockResponse(['data' => [
                    ['modelName' => 'qwen-flash', 'isLangfuseManaged' => false],
                    ['modelName' => 'mistral-small', 'isLangfuseManaged' => true],
                ], 'meta' => ['totalPages' => 1]]);
            }
            /** @var array{modelName: string, matchPattern: string, pricingTiers: list<array{isDefault: bool, prices: array<string, float>}>} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $posted[] = $body;

            return new JsonMockResponse(['id' => 'm1']);
        });

        $result = (new LangfusePriceDeclarer($http, 'http://langfuse-web:3000', 'pk', 'sk', $this->file))->declare();

        self::assertSame(['created' => ['mistral-small'], 'existing' => ['qwen-flash']], $result, 'a Langfuse-managed model of the same name does not count');
        self::assertCount(1, $posted);
        self::assertSame('mistral-small', $posted[0]['modelName']);
        self::assertSame('(?i)^(mistral\/|qwen\/|dashscope\/)?(mistral-small-(latest|2603))$', $posted[0]['matchPattern']);
        self::assertEqualsWithDelta(0.15 / 1_000_000, $posted[0]['pricingTiers'][0]['prices']['input'], 1e-15);
        self::assertTrue($posted[0]['pricingTiers'][0]['isDefault']);
    }

    public function testNotConfiguredWithoutKeys(): void
    {
        self::assertFalse((new LangfusePriceDeclarer(new MockHttpClient(), 'http://langfuse-web:3000', '', '', $this->file))->isConfigured());
        self::assertTrue((new LangfusePriceDeclarer(new MockHttpClient(), 'http://langfuse-web:3000', 'pk', 'sk', $this->file))->isConfigured());
    }
}
