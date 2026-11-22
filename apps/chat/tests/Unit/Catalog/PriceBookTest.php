<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Catalog;

use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(PriceBook::class)]
final class PriceBookTest extends TestCase
{
    /**
     * Langfuse's own matching: its PostgreSQL regexes, a project-defined model
     * before a Langfuse-managed one, the default pricing tier.
     */
    public function testAModelIsPricedTheWayLangfuseMatchesIt(): void
    {
        $book = $this->book([
            ['modelName' => 'gpt-5.4-nano', 'matchPattern' => '(?i)^(openai\/)?(gpt-5.4-nano)$', 'isLangfuseManaged' => true, 'startDate' => null, 'pricingTiers' => [
                ['isDefault' => true, 'prices' => ['input' => 2e-7, 'output' => 1.25e-6]],
                ['isDefault' => false, 'prices' => ['input' => 1e-7, 'output' => 6.25e-7]],
            ]],
            ['modelName' => 'my-nano', 'matchPattern' => '(?i)^(gpt-5.4-nano)$', 'isLangfuseManaged' => false, 'startDate' => null, 'pricingTiers' => [
                ['isDefault' => true, 'prices' => ['input' => 9e-7, 'output' => 9e-6]],
            ]],
            ['modelName' => 'claude', 'matchPattern' => '(?i)^(anthropic\/)?(claude-sonnet-4-6)$', 'isLangfuseManaged' => true, 'startDate' => null, 'prices' => ['input' => ['price' => 3e-6]]],
        ]);

        self::assertSame(['input' => 9e-7, 'output' => 9e-6], $book->prices('GPT-5.4-NANO'), 'the project overrides Langfuse');
        self::assertSame(['input' => 3e-6], $book->prices('claude-sonnet-4-6'), 'the legacy price shape is read too');
        self::assertNull($book->prices('gpt-5.4-nano-mini'), 'anchored patterns: no partial match');
        self::assertNull($book->prices('mistral-small-latest'));
    }

    public function testLangfuseDownMeansUnknownPricesNotAnError(): void
    {
        $book = new PriceBook(new MockHttpClient(static fn (): never => throw new \RuntimeException('connection refused')), new ArrayAdapter(), 'http://langfuse-web:3000', 'pk', 'sk');

        self::assertNull($book->prices('gpt-5.4-nano'));
    }

    /**
     * No Langfuse URL or key: no price source. Nothing is asked, no model is priced.
     */
    public function testWithoutLangfuseThereIsNoPriceSource(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new \LogicException('No price source: nothing is asked.'));

        foreach ([['', 'pk'], ['http://langfuse-web:3000', '']] as [$url, $publicKey]) {
            $book = new PriceBook($http, new ArrayAdapter(), $url, $publicKey, 'sk');

            self::assertFalse($book->isConfigured());
            self::assertNull($book->prices('claude-haiku-4-5'));
        }
        self::assertSame(0, $http->getRequestsCount());
        self::assertTrue($this->book([])->isConfigured());
    }

    /**
     * @param list<array<string, mixed>> $models
     */
    private function book(array $models): PriceBook
    {
        $http = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['data' => $models, 'meta' => ['totalPages' => 1]]));

        return new PriceBook($http, new ArrayAdapter(), 'http://langfuse-web:3000', 'pk', 'sk');
    }
}
