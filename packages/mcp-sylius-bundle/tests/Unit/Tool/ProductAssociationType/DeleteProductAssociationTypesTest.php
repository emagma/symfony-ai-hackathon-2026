<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAssociationType;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAssociationType\DeleteProductAssociationTypes;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeleteProductAssociationTypes::class)]
final class DeleteProductAssociationTypesTest extends McpToolTestCase
{
    public function testItDeletesWhatExistsReportsTheRestAndAuditsEachDeletion(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'a' => ['ok' => true, 'body' => ['code' => 'a', 'name' => 'A']],
            'ghost' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'b' => ['ok' => true, 'body' => ['code' => 'b', 'name' => 'B']],
        ]);
        $client->expects(self::once())->method('deleteMany')
            ->with(['a' => 'product-association-types/a', 'b' => 'product-association-types/b'])
            ->willReturn([
                'a' => ['ok' => true],
                'b' => ['ok' => false, 'status' => 409, 'error' => 'HTTP 409', 'body' => ['detail' => 'Referenced by a tax rate.']],
            ]);
        [$audit, $em] = $this->auditSpy();
        $staged = [];
        $em->method('persist')->willReturnCallback(static function (RecordedWrite $entry) use (&$staged): void {
            $staged[] = $entry;
        });

        $result = $this->call(new DeleteProductAssociationTypes($client, $audit), codes: ['a', 'ghost', 'b'], confirm: true);

        self::assertTrue($result['ok']);
        self::assertSame(['total' => 3, 'deleted' => 1, 'failed' => 2], array_intersect_key($result, ['total' => 1, 'deleted' => 1, 'failed' => 1]));
        self::assertSame(['a', 'ghost', 'b'], array_keys($result['results']));
        self::assertSame(['ok' => true, 'action' => 'deleted'], $result['results']['a']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'not_found'], $result['results']['ghost']);
        self::assertSame('conflict', $result['results']['b']['reason']);

        self::assertCount(1, $staged);
        self::assertSame('a', $staged[0]->getTargetCode());
        self::assertSame(['code' => 'a', 'name' => 'A'], $staged[0]->getBeforeState());
        self::assertSame($result['batchId'], $staged[0]->getBatchId());
    }

    public function testBlankOrDuplicateCodesRefuseTheBatch(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $result = $this->call(new DeleteProductAssociationTypes($client, $this->audit()), codes: ['a', '', 'a'], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame([1, 2], array_column($result['errors'], 'index'));
    }

    public function testADeclinedConfirmationDeletesNothing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['a' => ['ok' => true, 'body' => ['code' => 'a', 'name' => 'A']]]);
        $client->expects(self::never())->method('deleteMany');
        $tool = new DeleteProductAssociationTypes($client, $this->audit());

        $result = $this->wire($this->runWithElicitation(
            fn (): array => $tool(codes: ['a'], confirm: false, context: $this->elicitingContext()),
            ElicitAction::Decline,
        ));

        self::assertSame(['ok' => false, 'total' => 1, 'reason' => 'declined', 'deleted' => 0, 'failed' => 1, 'results' => ['a' => ['ok' => false, 'action' => 'failed', 'reason' => 'declined']]], $result);
    }
}
