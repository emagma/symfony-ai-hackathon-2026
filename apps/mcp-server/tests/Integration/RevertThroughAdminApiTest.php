<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\AuditWriter;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpAuditBundle\Tool\RevertMcpEdit;
use Emagma\McpCoreBundle\Authorization\AllowAllGrantsProvider;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\NullActorProvider;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(RevertMcpEdit::class)]
final class RevertThroughAdminApiTest extends McpToolTestCase
{
    /** The revert tool needs the entry the audit's writer records, which the journal contract does not give. */
    protected function audit(): AuditWriter
    {
        return new AuditWriter($this->createStub(EntityManagerInterface::class), $this->redactor());
    }

    /**
     * @return array{AuditWriter, EntityManagerInterface&MockObject}
     */
    protected function auditSpy(): array
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return [new AuditWriter($em, $this->redactor()), $em];
    }

    private const string LOCALE = 'en_US';

    private const string TAXON_CODE = 'dresses';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(RevertMcpEdit::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('revert_mcp_edit', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsFalseWhenEntryNotFound(): void
    {
        $repo = $this->createAuditRepository(findResult: null);

        $result = $this->makeRevertTool($repo)(id: 42, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('not_found', $result['reason']);
        self::assertSame(42, $result['id']);
    }

    public function testItReturnsFalseWhenAlreadyReverted(): void
    {
        $revertEntry = $this->makeTaxonEntry();
        $original = $this->makeTaxonEntry();
        $original->setRevertedBy($revertEntry);

        $repo = $this->createAuditRepository(findResult: $original);

        $result = $this->makeRevertTool($repo)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('already_reverted_by', $result['reason']);
    }

    public function testItRefusesAnEntryWithNothingToRestore(): void
    {
        $entry = new AuditEntry(
            tool: 'create_product',
            targetType: 'product',
            targetCode: 'my_product',
            beforeState: [],
            afterState: ['code' => 'my_product'],
            diff: [],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $result = $this->makeRevertTool($repo)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('nothing_to_restore', $result['reason']);
    }

    /** An erasure leaves the keys and replaces the values with the redaction marker. */
    public function testItRefusesAForgottenEntry(): void
    {
        $entry = $this->makeTaxonEntry();
        $entry->forget();
        $repo = $this->createAuditRepository(findResult: $entry);

        $result = $this->makeRevertTool($repo)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('redacted_snapshot', $result['reason']);
    }

    public function testItRefusesASnapshotWithoutAnAdminIri(): void
    {
        $entry = new AuditEntry(
            tool: 'update_something',
            targetType: 'something',
            targetCode: 'x',
            beforeState: ['code' => 'x', 'name' => 'Old'],
            afterState: ['code' => 'x', 'name' => 'New'],
            diff: [],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $result = $this->makeRevertTool($repo)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('unrevertable_snapshot', $result['reason']);
    }

    public function testItReturnsTargetNotFoundWhenTaxonGone(): void
    {
        $entry = $this->makeTaxonEntry();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->makeRevertTool($repo, $client)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('target_not_found', $result['reason']);
    }

    public function testItReturnsAlreadyAtTargetStateWhenNoDiff(): void
    {
        $beforeState = $this->taxonPayload('Old', 'old-slug');
        $entry = new AuditEntry(
            tool: 'update_taxon',
            targetType: 'taxon',
            targetCode: self::TAXON_CODE,
            beforeState: $beforeState,
            afterState: $this->taxonPayload('New', 'new-slug'),
            diff: [],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($beforeState);
        $client->expects(self::never())->method('put');

        $result = $this->makeRevertTool($repo, $client)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('already_at_target_state', $result['reason']);
    }

    public function testItPutsBeforeStateAndRecordsAuditOnConfirm(): void
    {
        $beforeState = $this->taxonPayload('Old Name', 'old-slug');
        $currentState = $this->taxonPayload('New Name', 'new-slug');
        $entry = new AuditEntry(
            tool: 'update_taxon',
            targetType: 'taxon',
            targetCode: self::TAXON_CODE,
            beforeState: $beforeState,
            afterState: $currentState,
            diff: [],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($currentState);

        $client->expects(self::once())
            ->method('put')
            ->with(
                'taxons/' . self::TAXON_CODE,
                $beforeState,
                [],
            )
            ->willReturn($beforeState);

        [$audit, $em] = $this->auditSpy();
        // record() persists once; markReverted() flushes again.
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (AuditEntry $e): bool => 'revert_mcp_edit' === $e->getTool() &&
                'taxon' === $e->getTargetType() &&
                self::TAXON_CODE === $e->getTargetCode(),
        ));
        $em->expects(self::exactly(2))->method('flush');

        $result = $this->makeRevertTool($repo, $client, $audit)(id: 1, confirm: true);

        self::assertTrue($result['reverted']);
        self::assertSame(1, $result['id']);
        self::assertSame('taxon', $result['targetType']);
        self::assertSame(self::TAXON_CODE, $result['targetCode']);
        self::assertArrayHasKey('diff', $result);
    }

    public function testABatchIsRevertedUnderOneConfirmationWithOneResultPerEntry(): void
    {
        $variant = static fn (string $code, int $price): array => ['@id' => '/api/v2/admin/product-variants/' . $code, 'code' => $code, 'channelPricings' => ['WEB' => ['price' => $price]]];
        $entry = static function (string $code, int $id, array $before) use ($variant): AuditEntry {
            $entry = new AuditEntry(tool: 'upsert_product_variants', targetType: 'product_variant', targetCode: $code, beforeState: $before, afterState: $variant($code, 800), diff: [], batchId: 'b-1');
            (new \ReflectionProperty(AuditEntry::class, 'id'))->setValue($entry, $id);

            return $entry;
        };
        $repo = $this->createAuditRepository(paginateItems: [
            $entry('NEW', 3, []),
            $entry('MUG_L', 2, $variant('MUG_L', 1000)),
            $entry('MUG_M', 1, $variant('MUG_M', 1000)),
        ]);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(static fn (\Stringable|string $path): array => $variant(substr((string) $path, \strlen('product-variants/')), 800));
        $client->expects(self::exactly(2))->method('put')->willReturnCallback(static fn (\Stringable|string $path, array $body): array => $body);

        $result = $this->makeRevertTool($repo, $client)(batchId: 'b-1', confirm: true);

        self::assertSame(['ok' => false, 'batchId' => 'b-1', 'total' => 3, 'reverted' => 2, 'failed' => 1], array_intersect_key($result, array_flip(['ok', 'batchId', 'total', 'reverted', 'failed'])));
        self::assertSame('nothing_to_restore', $result['results']['3']['reason'], 'a creation in the batch is reported, the rest goes through');
        self::assertTrue($result['results']['2']['reverted']);
        self::assertTrue($result['results']['1']['reverted']);
    }

    public function testIdAndBatchIdAreExclusive(): void
    {
        $tool = $this->makeRevertTool($this->createAuditRepository());

        self::assertSame('id_or_batch_id', $tool()['reason']);
        self::assertSame('id_or_batch_id', $tool(id: 1, batchId: 'b-1')['reason']);
    }

    public function testItRevertsAnyResourceWhoseSnapshotCarriesItsIri(): void
    {
        $before = [
            '@id' => '/api/v2/admin/promotions/summer',
            'code' => 'summer',
            'priority' => 5,
        ];
        $entry = new AuditEntry(
            tool: 'update_promotion',
            targetType: 'promotion',
            targetCode: 'summer',
            beforeState: $before,
            afterState: ['priority' => 9] + $before,
            diff: ['priority' => ['before' => 5, 'after' => 9]],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn(['priority' => 9] + $before);
        $client->expects(self::once())
            ->method('put')
            ->with('promotions/summer', $before, [])
            ->willReturn($before);

        $result = $this->makeRevertTool($repo, $client)(id: 1, confirm: true);

        self::assertTrue($result['reverted']);
        self::assertSame(['priority' => ['before' => 9, 'after' => 5]], $result['diff']);
    }

    /**
     * Sylius keeps `state` out of its update groups: a PUT does not undo a state machine transition. An entry
     * recorded as revertible - by a tool that did not declare #[Irreversible] - is caught after the write.
     */
    public function testItReportsAnIncompleteRevertRatherThanClaimingSuccess(): void
    {
        $before = [
            '@id' => '/api/v2/admin/product-reviews/7',
            'title' => 'Great mug',
            'status' => 'new',
        ];
        $entry = new AuditEntry(
            tool: 'accept_product_review',
            targetType: 'product_review',
            targetCode: '7',
            beforeState: $before,
            afterState: ['status' => 'accepted'] + $before,
            diff: ['status' => ['before' => 'new', 'after' => 'accepted']],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn(['status' => 'accepted'] + $before);
        $client->method('put')->willReturn(['status' => 'accepted'] + $before);

        [$audit, $em] = $this->auditSpy();
        // The original is not marked as reverted, or a new attempt would be blocked.
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->makeRevertTool($repo, $client, $audit)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('revert_incomplete', $result['reason']);
        self::assertArrayHasKey('status', $result['notApplied']);
    }

    /** Read back, each content element gets a new `/.well-known/genid/…` node and its keys change order. */
    public function testACmsPageRevertIgnoresRegeneratedBlankNodesAndKeyOrder(): void
    {
        $element = static fn (string $genid, string $heading, bool $flipped): array => $flipped
            ? ['configuration' => ['heading' => $heading, 'heading_type' => 'h1'], 'locale' => 'en_US', '@id' => '/api/v2/.well-known/genid/' . $genid, 'type' => 'heading', '@type' => 'ContentConfiguration']
            : ['@type' => 'ContentConfiguration', '@id' => '/api/v2/.well-known/genid/' . $genid, 'type' => 'heading', 'locale' => 'en_US', 'configuration' => ['heading_type' => 'h1', 'heading' => $heading]];
        $page = static fn (string $title, array $elements): array => [
            '@id' => '/api/v2/admin/cms/pages/fix-page',
            'code' => 'fix-page',
            'contentElements' => $elements,
            'translations' => ['fr_FR' => ['@id' => '/api/v2/admin/cms/page-translations/9', 'locale' => 'fr_FR', 'title' => $title]],
        ];
        $before = $page('Avant', [$element('aaa', 'Spring', false)]);
        $current = $page('Après', [$element('bbb', 'Spring', true)]);
        $entry = new AuditEntry(
            tool: 'upsert_cms_pages',
            targetType: 'cms_page',
            targetCode: 'fix-page',
            beforeState: $before,
            afterState: $current,
            diff: [],
        );
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($current);
        $client->expects(self::once())->method('put')->with('cms/pages/fix-page', $before, [])
            ->willReturn($page('Avant', [$element('ccc', 'Spring', true)]));

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::exactly(2))->method('flush');

        $result = $this->makeRevertTool($repo, $client, $audit)(id: 1, confirm: true);

        self::assertTrue($result['reverted']);
        self::assertSame(['fr_FR.title' => ['before' => 'Après', 'after' => 'Avant']], $result['diff']);
    }

    public function testACmsPageAlreadyAtItsSnapshotIsReportedAsSuch(): void
    {
        $before = ['@id' => '/api/v2/admin/cms/pages/fix-page', 'contentElements' => [
            ['@id' => '/api/v2/.well-known/genid/aaa', '@type' => 'ContentConfiguration', 'type' => 'spacer', 'configuration' => ['spacer' => 40]],
        ]];
        $current = ['@id' => '/api/v2/admin/cms/pages/fix-page', 'contentElements' => [
            ['configuration' => ['spacer' => 40], 'type' => 'spacer', '@type' => 'ContentConfiguration', '@id' => '/api/v2/.well-known/genid/bbb'],
        ]];
        $entry = new AuditEntry(tool: 'upsert_cms_pages', targetType: 'cms_page', targetCode: 'fix-page', beforeState: $before, afterState: $current, diff: []);

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn($current);
        $client->expects(self::never())->method('put');

        $result = $this->makeRevertTool($this->createAuditRepository(findResult: $entry), $client)(id: 1, confirm: true);

        self::assertSame('already_at_target_state', $result['reason']);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function changesThatStillDiff(): iterable
    {
        yield 'nested configuration value' => [
            [['type' => 'item_total', 'configuration' => ['WEB' => ['amount' => 5000]]]],
            [['type' => 'item_total', 'configuration' => ['WEB' => ['amount' => 6000]]]],
        ];
        yield 'list order' => [['/api/v2/admin/taxons/a', '/api/v2/admin/taxons/b'], ['/api/v2/admin/taxons/b', '/api/v2/admin/taxons/a']];
        yield 'embedded real IRI' => [['@id' => '/api/v2/admin/channels/WEB', '@type' => 'Channel'], ['@id' => '/api/v2/admin/channels/MOBILE', '@type' => 'Channel']];
        yield 'nested id' => [[['id' => 1, 'amount' => 5]], [['id' => 2, 'amount' => 5]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changesThatStillDiff')]
    public function testTheCanonicalComparisonStillSeesRealChanges(mixed $before, mixed $current): void
    {
        $target = ['@id' => '/api/v2/admin/promotions/summer', 'field' => $before];
        $entry = new AuditEntry(tool: 'update_promotion', targetType: 'promotion', targetCode: 'summer', beforeState: $target, afterState: [], diff: []);

        $client = $this->createMock(AdminApiClientInterface::class);
        $client->method('getDecoded')->willReturn(['field' => $current] + $target);

        $result = $this->makeRevertTool($this->createAuditRepository(findResult: $entry), $client)(id: 1);

        self::assertSame(['field'], array_keys($result['diff']));
    }

    public function testThePreviewCountsNestedListsAndKeepsFlatFields(): void
    {
        $elements = static fn (int $count): array => array_fill(0, $count, ['@id' => '/api/v2/.well-known/genid/x', '@type' => 'ContentConfiguration', 'type' => 'textarea', 'configuration' => ['textarea' => str_repeat('<p>long text</p>', 4)]]);
        $entry = new AuditEntry(tool: 'upsert_cms_pages', targetType: 'cms_page', targetCode: 'fix-page', beforeState: [], afterState: [], diff: []);
        $preview = (new \ReflectionMethod(RevertMcpEdit::class, 'renderRevertPreview'))->invoke(
            $this->makeRevertTool($this->createAuditRepository()),
            $entry,
            [
                'name' => ['before' => 'New', 'after' => 'Old'],
                'channels' => ['before' => ['/api/v2/admin/channels/WEB'], 'after' => []],
                'contentElements' => ['before' => $elements(4), 'after' => $elements(6)],
                'rules' => ['before' => $elements(2), 'after' => $elements(2)],
                'small' => ['before' => [['type' => 'spacer', '@type' => 'X']], 'after' => [['type' => 'heading']]],
            ],
        );

        self::assertIsString($preview);
        self::assertStringContainsString('- name: "New" -> "Old"', $preview);
        self::assertStringContainsString('- channels: ["/api/v2/admin/channels/WEB"] -> []', $preview);
        self::assertStringContainsString('- contentElements: 4 items -> 6 items', $preview);
        self::assertStringContainsString('- rules: 2 items changed', $preview);
        self::assertStringContainsString('- small: [[type: "spacer"]] -> [[type: "heading"]]', $preview);
        self::assertStringNotContainsString('genid', $preview);
    }

    public function testItsDescriptionNamesTheSearchTool(): void
    {
        $attribute = (new \ReflectionClass(RevertMcpEdit::class))->getAttributes(McpTool::class)[0]->newInstance();

        self::assertStringContainsString('search_mcp_edits', (string) $attribute->description);
        self::assertStringNotContainsString('list_mcp_edits', (string) $attribute->description);
    }

    public function testWithNullContextItReturnsPreviewOnly(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->expects(self::never())->method('put');

        $result = $this->makeRevertTool($repo, $client)(id: 1, context: null);

        self::assertFalse($result['reverted']);
        self::assertSame('elicitation_unavailable_preview_only', $result['reason']);
        self::assertArrayHasKey('diff', $result);
        self::assertArrayHasKey('hint', $result);
    }

    public function testNonElicitingClientReturnsPreviewOnly(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->expects(self::never())->method('put');

        $result = $this->makeRevertTool($repo, $client)(id: 1, context: $this->noElicitContext());

        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToRevert(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $beforeState = $entry->getBeforeState();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->expects(self::once())->method('put')->willReturn($beforeState);

        $tool = $this->makeRevertTool($repo, $client);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(id: 1, context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['reverted']);
    }

    public function testElicitationDeclinedDoesNotRevert(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->expects(self::never())->method('put');

        $tool = $this->makeRevertTool($repo, $client);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(id: 1, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['reverted']);
        self::assertSame('declined', $result['reason']);
    }

    public function testElicitationCancelledDoesNotRevert(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->expects(self::never())->method('put');

        $tool = $this->makeRevertTool($repo, $client);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(id: 1, context: $context),
            ElicitAction::Cancel,
        );

        self::assertSame('cancelled', $result['reason']);
    }

    public function testItMapsValidationErrorsOnRevertPut(): void
    {
        $entry = $this->makeTaxonEntryWithDiff();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->taxonPayload('New', 'new'));
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'translations[en_US].name', 'message' => 'Required.'],
        ]));

        $result = $this->makeRevertTool($repo, $client)(id: 1, confirm: true);

        self::assertFalse($result['reverted']);
        self::assertSame('validation_error', $result['reason']);
    }

    public function testANon404FetchFailureIsAnUpstreamError(): void
    {
        $entry = $this->makeTaxonEntry();
        $repo = $this->createAuditRepository(findResult: $entry);

        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        $result = $this->wire($this->makeRevertTool($repo, $client)(id: 1, confirm: true));

        self::assertSame(
            ['ok' => false, 'reverted' => false, 'id' => 1, 'reason' => 'upstream_error', 'status' => 500],
            array_intersect_key($result, ['ok' => 1, 'reverted' => 1, 'id' => 1, 'reason' => 1, 'status' => 1]),
        );
    }

    /**
     * @param list<AuditEntry> $paginateItems the entries of a batch
     */
    private function createAuditRepository(?AuditEntry $findResult = null, array $paginateItems = []): AuditRepositoryInterface
    {
        $repository = $this->createStub(AuditRepositoryInterface::class);
        $repository->method('find')->willReturn($findResult);
        $repository->method('findByBatch')->willReturn($paginateItems);

        return $repository;
    }

    /** Every entry, every tool: the scopes are tested by the audit bundle. */
    private function createAuditScope(): AuditScope
    {
        return new AuditScope(new AllowAllGrantsProvider(), new NullActorProvider(), new ToolPermissionResolver(), new ToolClassMap());
    }

    private function makeRevertTool(
        AuditRepositoryInterface $repo,
        ?\Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface $client = null,
        ?AuditWriter $audit = null,
    ): RevertMcpEdit {
        return new RevertMcpEdit(
            new \Emagma\McpSyliusBundle\AdminApi\AdminApiRevertTarget(
                $client ?? $this->apiClient(),
                new \Emagma\McpSyliusV2Bundle\Sylius2Adapter('http://shop.test/api/v2/admin/'),
            ),
            $repo,
            $audit ?? $this->audit(),
            // Every entry, every tool: the scopes are the Functional suite's.
            $this->createAuditScope(),
        );
    }

    private function makeTaxonEntry(): AuditEntry
    {
        return new AuditEntry(
            tool: 'update_taxon',
            targetType: 'taxon',
            targetCode: self::TAXON_CODE,
            beforeState: $this->taxonPayload('Old', 'old-slug'),
            afterState: $this->taxonPayload('New', 'new-slug'),
            diff: [self::LOCALE . '.name' => ['before' => 'Old', 'after' => 'New']],
        );
    }

    private function makeTaxonEntryWithDiff(): AuditEntry
    {
        return new AuditEntry(
            tool: 'update_taxon',
            targetType: 'taxon',
            targetCode: self::TAXON_CODE,
            beforeState: $this->taxonPayload('Old', 'old-slug'),
            afterState: $this->taxonPayload('New', 'new-slug'),
            diff: [],
        );
    }

    /** @return array<string, mixed> */
    private function taxonPayload(string $name, string $slug): array
    {
        return [
            '@id' => '/api/v2/admin/taxons/' . self::TAXON_CODE,
            '@type' => 'Taxon',
            'code' => self::TAXON_CODE,
            'enabled' => true,
            'translations' => [
                self::LOCALE => ['name' => $name, 'slug' => $slug, 'description' => null],
            ],
        ];
    }
}
