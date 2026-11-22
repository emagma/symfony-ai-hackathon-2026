<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Product;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * `productTaxons` is read-only on the admin Product API: writes go through the ProductTaxon resource.
 * Not revertable via `revert_mcp_edit`: the join is not part of a resource snapshot.
 *
 * @internal
 */
#[McpTool(
    name: 'update_product_taxons',
    description: 'Bulk add/remove product ↔ taxon attachments (secondary productTaxons collection). Each item:'
        . ' {productCode, taxonCode, operation: "add" | "remove", position? (add only, default 0)}.'
        . ' Idempotent - add on an already-attached pair reports `already_attached` ; remove on a missing'
        . ' pair reports `not_attached` ; neither triggers a write. Mixed add/remove in one call is'
        . ' supported. Capped at 200 items per call. Returns {updated: N, total: count, results:'
        . ' list<{productCode, taxonCode, operation, updated: bool, reason?}>}. Not revertable.',
)]
#[Permission(resource: 'product', action: Action::Write)]
#[Permission(resource: 'taxon', action: Action::Read)]
#[Irreversible]
final readonly class UpdateProductTaxons
{
    use LeanLocaleHeaderTrait;
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_taxon';

    private const int MAX_ITEMS = 200;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<array{
     *     productCode?: mixed, taxonCode?: mixed, operation?: mixed, position?: mixed
     * }> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        array $items,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ([] === $items) {
            return ['ok' => true, 'updated' => 0, 'total' => 0, 'results' => []];
        }
        if (\count($items) > self::MAX_ITEMS) {
            return [
                'ok' => false,
                'updated' => 0,
                'total' => \count($items),
                'reason' => 'too_many_items',
                'hint' => 'Cap is ' . self::MAX_ITEMS . ' items per call ; chunk client-side.',
            ];
        }

        [$shaped, $shapeErrors] = $this->validateAndShape($items);
        if ([] !== $shapeErrors) {
            return [
                'ok' => false,
                'updated' => 0,
                'total' => \count($items),
                'reason' => 'items_invalid',
                'errors' => $shapeErrors,
            ];
        }

        $productCodes = [];
        $taxonCodes = [];
        foreach ($shaped as $row) {
            $productCodes[$row['productCode']] = true;
            $taxonCodes[$row['taxonCode']] = true;
        }
        /** @var array<string, string> $productPaths */
        $productPaths = [];
        foreach (array_keys($productCodes) as $code) {
            $productPaths[$code] = Route::item(Resource::Product, $code);
        }
        /** @var array<string, string> $taxonPaths */
        $taxonPaths = [];
        foreach (array_keys($taxonCodes) as $code) {
            $taxonPaths[$code] = Route::item(Resource::Taxon, $code);
        }

        $fetchedProducts = $this->client->getDecodedMany($productPaths);
        $fetchedTaxons = $this->client->getDecodedMany($taxonPaths);

        $taxonIris = [];
        foreach ($fetchedTaxons as $code => $r) {
            if (true === $r['ok'] && \is_string($r['body']['@id'] ?? null)) {
                $taxonIris[(string) $code] = $r['body']['@id'];
            }
        }

        // The Product payload lists productTaxons as bare IRIs: each one is fetched to learn its taxon code.
        /** @var array<string, array{iri: string, productTaxonIris: list<string>, attachments: array<string, array{'@id': string, position: ?int}>}> $products */
        $products = [];
        $allProductTaxonIris = [];
        foreach ($fetchedProducts as $code => $r) {
            if (true !== $r['ok']) {
                continue;
            }
            $body = $r['body'];
            $iri = \is_string($body['@id'] ?? null) ? $body['@id'] : null;
            if (null === $iri) {
                continue;
            }
            /** @var list<string> $ptIris */
            $ptIris = [];
            $rawTaxons = \is_array($body['productTaxons'] ?? null) ? $body['productTaxons'] : [];
            foreach ($rawTaxons as $pt) {
                if (\is_string($pt) && '' !== $pt) {
                    $ptIris[] = $pt;
                    $allProductTaxonIris[$pt] = true;
                } elseif (\is_array($pt) && \is_string($pt['@id'] ?? null) && '' !== $pt['@id']) {
                    $ptIris[] = $pt['@id'];
                    $allProductTaxonIris[$pt['@id']] = true;
                }
            }
            $products[(string) $code] = ['iri' => $iri, 'productTaxonIris' => $ptIris, 'attachments' => []];
        }

        if ([] !== $allProductTaxonIris) {
            /** @var array<string, string> $ptPaths */
            $ptPaths = [];
            foreach (array_keys($allProductTaxonIris) as $iri) {
                $ptPaths[$iri] = $this->adapter->pathFromIri($iri);
            }
            $hydrated = $this->client->getDecodedMany($ptPaths);
            $ptByIri = [];
            foreach ($hydrated as $iri => $r) {
                if (true !== $r['ok']) {
                    continue;
                }
                $taxonCode = AdminPayload::code($r['body']['taxon'] ?? null);
                if (null === $taxonCode) {
                    continue;
                }
                $position = \is_int($r['body']['position'] ?? null) ? $r['body']['position'] : null;
                $ptByIri[(string) $iri] = ['taxonCode' => $taxonCode, 'position' => $position];
            }
            foreach ($products as $productCode => &$productData) {
                foreach ($productData['productTaxonIris'] as $iri) {
                    if (!isset($ptByIri[$iri])) {
                        continue;
                    }
                    $info = $ptByIri[$iri];
                    $productData['attachments'][$info['taxonCode']] = [
                        '@id' => $iri,
                        'position' => $info['position'],
                    ];
                }
            }
            unset($productData);
        }

        /** @var array<int, array<string, mixed>> $results */
        $results = [];
        /** @var array<int, array{path: string, body: array<string, mixed>}> $postQueue */
        $postQueue = [];
        /** @var array<int, string> $deleteQueue */
        $deleteQueue = [];
        /** @var array<int, array{productTaxonIri?: string, position?: ?int, taxonIri?: string}> $auditContext */
        $auditContext = [];

        foreach ($shaped as $i => $row) {
            $entry = [
                'productCode' => $row['productCode'],
                'taxonCode' => $row['taxonCode'],
                'operation' => $row['operation'],
            ];
            if (!isset($products[$row['productCode']])) {
                $results[$i] = $entry + ['updated' => false, 'reason' => 'product_not_found'];

                continue;
            }
            if (!isset($taxonIris[$row['taxonCode']])) {
                $results[$i] = $entry + ['updated' => false, 'reason' => 'taxon_not_found'];

                continue;
            }
            $existing = $products[$row['productCode']]['attachments'][$row['taxonCode']] ?? null;

            if ('add' === $row['operation']) {
                if (null !== $existing) {
                    $results[$i] = $entry + ['updated' => false, 'reason' => 'already_attached'];

                    continue;
                }
                $position = $row['position'] ?? 0;
                $postQueue[$i] = [
                    'path' => 'product-taxons',
                    'body' => [
                        'product' => $products[$row['productCode']]['iri'],
                        'taxon' => $taxonIris[$row['taxonCode']],
                        'position' => $position,
                    ],
                ];
                $auditContext[$i] = ['position' => $position, 'taxonIri' => $taxonIris[$row['taxonCode']]];
            } else {
                if (null === $existing) {
                    $results[$i] = $entry + ['updated' => false, 'reason' => 'not_attached'];

                    continue;
                }
                $deleteQueue[$i] = $existing['@id'];
                $auditContext[$i] = ['productTaxonIri' => $existing['@id'], 'position' => $existing['position'], 'taxonIri' => $taxonIris[$row['taxonCode']]];
            }
        }

        if ([] === $postQueue && [] === $deleteQueue) {
            return [
                'ok' => true,
                'updated' => 0,
                'total' => \count($items),
                'results' => array_values($this->reindexResults($shaped, $results)),
            ];
        }

        // Always run the gate: short-circuiting on the model-supplied $confirm would let a forged one skip elicitation.
        $previewable = array_keys($postQueue + $deleteQueue);
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm bulk product-taxon update on ' . \count($previewable) . ' item(s)',
            'Apply these ' . \count($previewable) . ' product-taxon writes?',
            $this->renderBulkPreview($shaped, $postQueue, $deleteQueue, $results),
            'Re-invoke with confirm: true to apply these changes.',
        );
        if (null !== $refusal) {
            foreach ($previewable as $i) {
                $results[$i] = [
                    'productCode' => $shaped[$i]['productCode'],
                    'taxonCode' => $shaped[$i]['taxonCode'],
                    'operation' => $shaped[$i]['operation'],
                    'updated' => false,
                    'reason' => $refusal['reason'],
                ];
            }

            $out = [
                'updated' => 0,
                'total' => \count($items),
                'results' => array_values($this->reindexResults($shaped, $results)),
            ];
            if (isset($refusal['hint'])) {
                $out['hint'] = $refusal['hint'];
            }

            return $out;
        }

        // Sequential: Sylius's position-reorder listener locks the parent taxon row, concurrent POSTs deadlock.
        $postResults = $this->sequentialPost($postQueue);
        $deleteResults = $this->sequentialDelete($deleteQueue);

        $successCount = 0;

        foreach ($postQueue as $i => $_) {
            $r = $postResults[$i] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            $row = $shaped[$i];
            $entry = [
                'productCode' => $row['productCode'],
                'taxonCode' => $row['taxonCode'],
                'operation' => 'add',
            ];
            if (true === $r['ok']) {
                $body = $r['body'];
                $createdIri = \is_string($body['@id'] ?? null) ? $body['@id'] : null;
                $position = \is_int($body['position'] ?? null) ? $body['position'] : ($auditContext[$i]['position'] ?? 0);
                $this->audit->record(
                    tool: 'update_product_taxons',
                    targetType: self::TARGET_TYPE,
                    targetCode: $row['productCode'] . ':' . $row['taxonCode'],
                    beforeState: [],
                    afterState: [
                        'productTaxonIri' => $createdIri,
                        'product' => $row['productCode'],
                        'taxon' => $row['taxonCode'],
                        'position' => $position,
                    ],
                    diff: ['operation' => ['before' => null, 'after' => 'add'], 'position' => ['before' => null, 'after' => $position]],
                    context: $context,
                );
                $results[$i] = $entry + ['updated' => true, 'position' => $position];
                ++$successCount;
            } elseif (false === $r['ok'] && 422 === $r['status']) {
                $body = $r['body'] ?? null;
                $results[$i] = $entry + ['updated' => false, 'reason' => 'validation_error', 'errors' => $this->violations($body)];
            } elseif (false === $r['ok']) {
                $results[$i] = $entry + ['updated' => false, 'reason' => 'post_failed', 'status' => $r['status'], 'error' => $r['error']];
            }
        }

        foreach ($deleteQueue as $i => $iri) {
            $r = $deleteResults[$i] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            $row = $shaped[$i];
            $entry = [
                'productCode' => $row['productCode'],
                'taxonCode' => $row['taxonCode'],
                'operation' => 'remove',
            ];
            if (true === $r['ok']) {
                $ctx = $auditContext[$i] ?? [];
                $this->audit->record(
                    tool: 'update_product_taxons',
                    targetType: self::TARGET_TYPE,
                    targetCode: $row['productCode'] . ':' . $row['taxonCode'],
                    beforeState: [
                        'productTaxonIri' => $ctx['productTaxonIri'] ?? $iri,
                        'product' => $row['productCode'],
                        'taxon' => $row['taxonCode'],
                        'position' => $ctx['position'] ?? null,
                    ],
                    afterState: [],
                    diff: ['operation' => ['before' => null, 'after' => 'remove']],
                    context: $context,
                );
                $results[$i] = $entry + ['updated' => true];
                ++$successCount;
            } elseif (false === $r['ok']) {
                $results[$i] = $entry + ['updated' => false, 'reason' => 'delete_failed', 'status' => $r['status'], 'error' => $r['error']];
            }
        }

        return [
            'ok' => true,
            'updated' => $successCount,
            'total' => \count($items),
            'results' => array_values($this->reindexResults($shaped, $results)),
        ];
    }

    /**
     * @param list<array{productCode?: mixed, taxonCode?: mixed, operation?: mixed, position?: mixed}> $items
     *
     * @return array{0: list<array{productCode: string, taxonCode: string, operation: 'add'|'remove', position: ?int}>, 1: list<array{index: int, message: string}>}
     */
    private function validateAndShape(array $items): array
    {
        $shaped = [];
        $errors = [];
        foreach ($items as $i => $item) {
            if (!\is_array($item)) {
                $errors[] = ['index' => $i, 'message' => 'item must be an object'];

                continue;
            }
            $productCode = $item['productCode'] ?? null;
            if (!\is_string($productCode) || '' === $productCode) {
                $errors[] = ['index' => $i, 'message' => 'missing or empty "productCode"'];

                continue;
            }
            $taxonCode = $item['taxonCode'] ?? null;
            if (!\is_string($taxonCode) || '' === $taxonCode) {
                $errors[] = ['index' => $i, 'message' => 'missing or empty "taxonCode"'];

                continue;
            }
            $operation = $item['operation'] ?? null;
            if ('add' !== $operation && 'remove' !== $operation) {
                $errors[] = ['index' => $i, 'message' => '"operation" must be "add" or "remove"'];

                continue;
            }
            $position = $item['position'] ?? null;
            if (null !== $position && !\is_int($position)) {
                $errors[] = ['index' => $i, 'message' => '"position" must be an integer if provided'];

                continue;
            }
            $shaped[] = [
                'productCode' => $productCode,
                'taxonCode' => $taxonCode,
                'operation' => $operation,
                'position' => $position,
            ];
        }

        return [$shaped, $errors];
    }

    /**
     * @param list<array{productCode: string, taxonCode: string, operation: 'add'|'remove', position: ?int}> $shaped
     * @param array<int, array{path: string, body: array<string, mixed>}>                                   $postQueue
     * @param array<int, string>                                                                            $deleteQueue
     * @param array<int, array<string, mixed>>                                                              $alreadyResolved
     */
    private function renderBulkPreview(array $shaped, array $postQueue, array $deleteQueue, array $alreadyResolved): string
    {
        $lines = [
            'Bulk product-taxon update - ' . \count($postQueue) . ' add(s), ' . \count($deleteQueue) . ' remove(s)',
            '',
        ];
        $sample = 0;
        foreach ($postQueue as $i => $entry) {
            if ($sample++ >= 10) {
                break;
            }
            $position = \is_int($entry['body']['position'] ?? null) ? $entry['body']['position'] : 0;
            $lines[] = sprintf('+ %s ↔ %s (pos=%d)', $shaped[$i]['productCode'], $shaped[$i]['taxonCode'], $position);
        }
        foreach ($deleteQueue as $i => $_) {
            if ($sample++ >= 20) {
                break;
            }
            $lines[] = sprintf('- %s ↔ %s', $shaped[$i]['productCode'], $shaped[$i]['taxonCode']);
        }
        $remaining = (\count($postQueue) + \count($deleteQueue)) - $sample;
        if ($remaining > 0) {
            $lines[] = '+ ' . $remaining . ' more';
        }
        if ([] !== $alreadyResolved) {
            $skipped = array_count_values(array_map(
                static fn (array $r): string => \is_string($r['reason'] ?? null) ? $r['reason'] : 'unknown',
                $alreadyResolved,
            ));
            $lines[] = '';
            $lines[] = 'Skipped: ' . http_build_query($skipped, '', ', ');
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{productCode: string, taxonCode: string, operation: 'add'|'remove', position: ?int}> $shaped
     * @param array<int, array<string, mixed>>                                                               $results
     *
     * @return array<int, array<string, mixed>>
     */
    private function reindexResults(array $shaped, array $results): array
    {
        $ordered = [];
        foreach (array_keys($shaped) as $i) {
            if (isset($results[$i])) {
                $ordered[$i] = $results[$i];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, array{path: string, body: array<string, mixed>}> $queue
     *
     * @return array<int, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    private function sequentialPost(array $queue): array
    {
        $results = [];
        foreach ($queue as $i => $entry) {
            try {
                $body = $this->client->post($entry['path'], $entry['body'], self::leanHeaders());
                $results[$i] = ['ok' => true, 'body' => $body];
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $payload = $e->getResponse()->toArray(throw: false);
                $results[$i] = ['ok' => false, 'status' => $status, 'error' => $e->getMessage(), 'body' => $payload];
            } catch (\Throwable $e) {
                $results[$i] = ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'body' => null];
            }
        }

        return $results;
    }

    /**
     * @param array<int, string> $queue path => productTaxon IRI
     *
     * @return array<int, array{ok: true}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null}>
     */
    private function sequentialDelete(array $queue): array
    {
        $results = [];
        foreach ($queue as $i => $iri) {
            try {
                // AdminApiClient has no single delete(): deleteMany on one entry keeps its reauth-and-retry.
                $r = $this->client->deleteMany([$iri], self::leanHeaders());
                /** @var array{ok: true}|array{ok: false, status: int|null, error: string, body: array<string, mixed>|null} $first */
                $first = $r[0] ?? ['ok' => false, 'status' => null, 'error' => 'no_response', 'body' => null];
                $results[$i] = $first;
            } catch (\Throwable $e) {
                $results[$i] = ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'body' => null];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return list<array{property: string, message: string}>
     */
    private function violations(?array $payload): array
    {
        if (null === $payload) {
            return [];
        }
        $violations = $payload['violations'] ?? $payload['hydra:violations'] ?? [];
        if (!\is_array($violations)) {
            return [];
        }
        $out = [];
        foreach ($violations as $v) {
            if (!\is_array($v)) {
                continue;
            }
            $out[] = [
                'property' => (string) ($v['propertyPath'] ?? ''),
                'message' => (string) ($v['message'] ?? ''),
            ];
        }

        return $out;
    }
}
