<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

final class Scenario15HistoryAndRevertTest extends QaScenarioTestCase
{
    private const string PRODUCT = 'Bold_Beach_Cap';

    public function testAChangeOfTheDayIsListedAndReverted(): void
    {
        $name = $this->productName();
        self::assertBatchApplied($this->call('upsert_products', ['items' => [['code' => self::PRODUCT, 'name' => 'Bold Beach Cap (QA)']], 'confirm' => true]));

        $edits = $this->searchAll('search_mcp_edits', ['since' => (new \DateTimeImmutable('today'))->format(\DATE_ATOM)]);
        $edit = array_values(array_filter($edits, static fn (array $edit): bool => self::PRODUCT === $edit['targetCode']));
        self::assertCount(1, $edit, 'the change is in the history of the day');

        $revert = $this->call('revert_mcp_edit', ['id' => $edit[0]['id'], 'confirm' => true]);
        self::assertTrue($revert['ok'] ?? false, (string) json_encode($revert));
        self::assertSame($name, $this->productName());
    }

    public function testABulkPriceDropIsRevertedInOneRequest(): void
    {
        $codes = [];
        foreach ($this->searchAll('search_products', ['taxon' => 'caps_with_pompons']) as $product) {
            array_push($codes, ...array_column($this->searchAll('search_product_variants', ['product' => $product['code']]), 'code'));
        }
        $codes = \array_slice($codes, 0, 5);
        /** @var list<array{code: string, pricing: array<string, int>}> $before */
        $before = $this->call('get_product_variants', ['codes' => $codes])['found'];

        $items = array_map(static fn (array $variant): array => [
            'code' => $variant['code'],
            'pricing' => array_map(static fn (int $price): int => (int) round($price * 0.8), $variant['pricing']),
        ], $before);
        $drop = $this->call('upsert_product_variants', ['items' => $items, 'mode' => 'update', 'confirm' => true]);
        self::assertBatchApplied($drop);
        self::assertIsString($drop['batchId'] ?? null);

        self::assertCount(\count($codes), $this->searchAll('search_mcp_edits', ['batchId' => $drop['batchId']]), 'one history entry per variant, under the batch');

        $revert = $this->call('revert_mcp_edit', ['batchId' => $drop['batchId'], 'confirm' => true]);
        self::assertTrue($revert['ok'] ?? false, (string) json_encode($revert));

        /** @var list<array{code: string, pricing: array<string, int>}> $after */
        $after = $this->call('get_product_variants', ['codes' => $codes])['found'];
        self::assertSame(array_column($before, 'pricing', 'code'), array_column($after, 'pricing', 'code'));
    }

    private function productName(): ?string
    {
        /** @var array{found: list<array{name: ?string}>} $result */
        $result = $this->call('get_products', ['codes' => [self::PRODUCT]]);

        return $result['found'][0]['name'];
    }
}
