<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\TaxCategory;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\TaxCategory\UpsertTaxCategories;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(UpsertTaxCategories::class)]
final class UpsertTaxCategoriesTest extends McpToolTestCase
{
    public function testAnEmptyBatchIsANoOp(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: [], confirm: true);

        self::assertSame(['ok' => true, 'total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'results' => []], $result);
    }

    public function testTooManyItemsAreRefusedBeforeAnyRequest(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');
        $items = array_map(static fn (int $i): array => ['code' => 'c' . $i, 'name' => 'C' . $i], range(1, 51));

        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: $items, confirm: true);

        self::assertFalse($result['ok']);
        self::assertSame('too_many_items', $result['reason']);
        self::assertSame(51, $result['total']);
    }

    public function testMalformedItemsRefuseTheWholeBatchWithTheirIndex(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: [
            ['code' => 'ok', 'name' => 'Fine'],
            ['code' => '  ', 'name' => 'Blank code would hit the collection route'],
            ['code' => 'ok', 'name' => 'Twice'],
            ['code' => 'bad-name', 'name' => ''],
            'not an object',
        ], confirm: true);

        self::assertFalse($result['ok']);
        self::assertSame('items_invalid', $result['reason']);
        self::assertSame([1, 2, 3, 4], array_column($result['errors'], 'index'));
        self::assertStringContainsString('twice', $result['errors'][1]['message']);
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $result = $this->call(new UpsertTaxCategories($this->apiClient(), $this->audit()), items: [['code' => 'a']], mode: 'merge', confirm: true);

        self::assertSame('invalid_mode', $result['reason']);
    }

    public function testUpsertCreatesTheMissingUpdatesTheRestAndLeavesTheUnchangedAlone(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, ['standard' => ['code' => 'standard', 'name' => 'Standard', 'description' => 'VAT 20%'], 'same' => ['code' => 'same', 'name' => 'Same']]);
        $client->expects(self::once())->method('postMany')
            ->with(['reduced' => ['path' => 'tax-categories', 'body' => ['code' => 'reduced', 'name' => 'Reduced']]])
            ->willReturn(['reduced' => ['ok' => true, 'body' => ['code' => 'reduced', 'name' => 'Reduced']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['standard' => ['path' => 'tax-categories/standard', 'body' => ['code' => 'standard', 'name' => 'Standard rate', 'description' => 'VAT 20%']]])
            ->willReturn(['standard' => ['ok' => true, 'body' => ['code' => 'standard', 'name' => 'Standard rate', 'description' => 'VAT 20%']]]);
        [$audit, $em] = $this->auditSpy();
        $staged = [];
        $em->method('persist')->willReturnCallback(static function (RecordedWrite $entry) use (&$staged): void {
            $staged[] = $entry;
        });

        $result = $this->call(new UpsertTaxCategories($client, $audit), items: [
            ['code' => 'standard', 'name' => 'Standard rate'],
            ['code' => 'reduced', 'name' => 'Reduced'],
            ['code' => 'same', 'name' => 'Same'],
        ], confirm: true);

        self::assertTrue($result['ok']);
        self::assertSame(['total' => 3, 'created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 0], array_intersect_key($result, ['total' => 1, 'created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1]));
        self::assertSame(['standard', 'reduced', 'same'], array_keys($result['results']), 'the caller\'s order');
        self::assertSame(['ok' => true, 'action' => 'updated', 'diff' => ['name' => ['before' => 'Standard', 'after' => 'Standard rate']]], $result['results']['standard']);
        self::assertSame('created', $result['results']['reduced']['action']);
        self::assertSame(['ok' => true, 'action' => 'unchanged'], $result['results']['same']);

        self::assertCount(2, $staged, 'one audit entry per write, none for the unchanged item');
        self::assertEqualsCanonicalizing(['standard', 'reduced'], array_map(static fn (RecordedWrite $e): string => $e->getTargetCode(), $staged));
        self::assertSame($result['batchId'], $staged[0]->getBatchId());
        self::assertSame($staged[0]->getBatchId(), $staged[1]->getBatchId());
        self::assertSame('upsert_tax_categories', $staged[0]->getTool());
    }

    public function testModeCreateRefusesAnExistingCodeAndModeUpdateAnUnknownOne(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, ['standard' => ['code' => 'standard', 'name' => 'Standard']]);
        $client->expects(self::never())->method('postMany');
        $client->expects(self::never())->method('putMany');
        $tool = new UpsertTaxCategories($client, $this->audit());

        $create = $this->call($tool, items: [['code' => 'standard', 'name' => 'X']], mode: 'create', confirm: true);
        $update = $this->call($tool, items: [['code' => 'ghost', 'name' => 'X']], mode: 'update', confirm: true);

        self::assertTrue($create['ok'], 'a refused item is not a refused batch');
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'already_exists'], $create['results']['standard']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'not_found'], $update['results']['ghost']);
        self::assertSame(1, $update['failed']);
    }

    public function testAnItemTheShopRefusesFailsAloneAndTheOthersGoThrough(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, []);
        $client->method('postMany')->willReturn([
            'a' => ['ok' => true, 'body' => ['code' => 'a', 'name' => 'A']],
            'b' => ['ok' => false, 'status' => 422, 'error' => 'HTTP 422', 'body' => ['violations' => [['propertyPath' => 'name', 'message' => 'Too long.']]]],
            'c' => ['ok' => false, 'status' => 500, 'error' => 'HTTP 500', 'body' => null],
        ]);

        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: [
            ['code' => 'a', 'name' => 'A'], ['code' => 'b', 'name' => 'B'], ['code' => 'c', 'name' => 'C'],
        ], confirm: true);

        self::assertTrue($result['ok']);
        self::assertSame(['created' => 1, 'failed' => 2], array_intersect_key($result, ['created' => 1, 'failed' => 1]));
        self::assertSame('validation_error', $result['results']['b']['reason']);
        self::assertSame([['property' => 'name', 'message' => 'Too long.']], $result['results']['b']['errors']);
        self::assertSame(['reason' => 'upstream_error', 'status' => 500], array_intersect_key($result['results']['c'], ['reason' => 1, 'status' => 1]));
    }

    public function testAFailedExistenceProbeFailsTheItemInsteadOfCreatingADuplicate(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['a' => ['ok' => false, 'status' => 503, 'error' => 'HTTP 503', 'body' => null]]);
        $client->expects(self::never())->method('postMany');

        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: [['code' => 'a', 'name' => 'A']], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'upstream_error', 'status' => 503], $result['results']['a']);
    }

    public function testADeclinedConfirmationWritesNothingAndSaysSoPerItem(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, []);
        $client->expects(self::never())->method('postMany');
        $tool = new UpsertTaxCategories($client, $this->audit());

        $result = $this->wire($this->runWithElicitation(
            fn (): array => $tool(items: [['code' => 'a', 'name' => 'A']], confirm: false, context: $this->elicitingContext()),
            ElicitAction::Decline,
        ));

        self::assertFalse($result['ok']);
        self::assertSame('declined', $result['reason']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'declined', 'diff' => ['code' => ['before' => null, 'after' => 'a'], 'name' => ['before' => null, 'after' => 'A']]], $result['results']['a']);
    }

    public function testAnAcceptedConfirmationWrites(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, []);
        $client->expects(self::once())->method('postMany')->willReturn(['a' => ['ok' => true, 'body' => ['code' => 'a', 'name' => 'A']]]);
        $tool = new UpsertTaxCategories($client, $this->audit());

        $result = $this->wire($this->runWithElicitation(
            fn (): array => $tool(items: [['code' => 'a', 'name' => 'A']], confirm: false, context: $this->elicitingContext()),
            ElicitAction::Accept,
            ['confirm' => true],
        ));

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['results']['a']['action']);
    }

    public function testNothingToWriteNeedsNoConfirmation(): void
    {
        $client = $this->apiClient();
        $this->shopKnows($client, ['same' => ['code' => 'same', 'name' => 'Same']]);

        // No context and confirm: false would be refused if the gate ran.
        $result = $this->call(new UpsertTaxCategories($client, $this->audit()), items: [['code' => 'same', 'name' => 'Same']]);

        self::assertSame(['ok' => true, 'total' => 1, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'failed' => 0, 'results' => ['same' => ['ok' => true, 'action' => 'unchanged']]], $result);
    }

    /**
     * @param array<string, array<string, mixed>> $existing code => payload
     */
    private function shopKnows(MockObject $client, array $existing): void
    {
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($existing): array {
            $out = [];
            foreach ($paths as $code => $path) {
                $out[$code] = isset($existing[$code])
                    ? ['ok' => true, 'body' => $existing[$code]]
                    : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
            }

            return $out;
        });
    }
}
