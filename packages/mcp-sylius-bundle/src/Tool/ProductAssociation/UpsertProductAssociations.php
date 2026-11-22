<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * The resource has no code: the item key is the (owner, type) pair, probed by one filtered page.
 * The admin filters are partial, so the pair is matched exactly here.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_product_associations',
    description: 'Create or update product associations (cross-selling links) in one call, at most 50 items,'
        . ' matched on their (ownerCode, typeCode) pair. associatedProductCodes replaces the list on an'
        . ' existing association and is required on a creation; every product and the type must exist.'
        . ' mode: upsert (default), create (already_exists on a known pair) or update (not_found on an'
        . ' unknown one). An item that changes nothing is unchanged and not written. One confirmation for'
        . ' the batch; one result per item, keyed "OWNER/TYPE". Tool-specific reasons: fields_required,'
        . ' owner_not_found, type_not_found, associated_products_not_found.',
)]
#[Permission(resource: 'product_association', action: Action::Write)]
#[Permission(resource: 'product', action: Action::Read)]
final readonly class UpsertProductAssociations
{
    use BatchUpsertTrait;
    use LeanLocaleHeaderTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private ReferenceResolver $references,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ProductAssociationItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_product_associations', Route::collection(Resource::ProductAssociation), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_association';
    }

    private function label(): string
    {
        return 'product associations';
    }

    /**
     * @param array<mixed> $item
     */
    private function keyOf(array $item): ?string
    {
        $owner = $item['ownerCode'] ?? null;
        $type = $item['typeCode'] ?? null;
        if (!\is_string($owner) || '' === trim($owner) || !\is_string($type) || '' === trim($type)) {
            return null;
        }

        return trim($owner) . '/' . trim($type);
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('associatedProductCodes', $item)) {
            $codes = $item['associatedProductCodes'];
            if (!\is_array($codes) || [] === $codes || [] !== array_filter($codes, static fn (mixed $c): bool => !\is_string($c) || '' === trim($c))) {
                return 'associatedProductCodes is a non-empty list of product codes (delete the association to remove it)';
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}>
     */
    private function probe(Route $collection, array $keys): array
    {
        $paths = [];
        foreach ($keys as $key) {
            [$owner, $type] = explode('/', $key, 2);
            $paths[$key] = $collection->withQuery(['owner.code' => $owner, 'type.code' => $type, 'itemsPerPage' => 50]);
        }
        $pages = $this->client->getDecodedMany($paths, self::leanHeaders());

        /** @var array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes */
        $probes = [];
        foreach ($keys as $key) {
            [$owner, $type] = explode('/', $key, 2);
            $page = $pages[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            if (true !== $page['ok']) {
                $probes[$key] = ['ok' => false, 'status' => $page['status'], 'error' => $page['error']];

                continue;
            }
            $match = null;
            foreach (AdminPayload::members($page['body']) as $member) {
                if (AdminPayload::code($member['owner'] ?? null) === $owner && AdminPayload::code($member['type'] ?? null) === $type) {
                    $match = $member;

                    break;
                }
            }
            $probes[$key] = null === $match
                ? ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']
                : ['ok' => true, 'body' => $match];
        }

        return $probes;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function writePath(Route $collection, string $key, array $current): Route
    {
        return $collection->withKey((string) AdminPayload::identifier($current));
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array<string, array<string, string>>}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $wanted = [Resource::Product->value => [], Resource::ProductAssociationType->value => []];
        foreach ($itemsByKey as $key => $item) {
            [$owner, $type] = explode('/', $key, 2);
            $probe = $probes[$key] ?? null;
            if (null === $probe || true !== $probe['ok']) {
                $wanted[Resource::Product->value][] = $owner;
                $wanted[Resource::ProductAssociationType->value][] = $type;
            }
            array_push($wanted[Resource::Product->value], ...self::codesOf($item));
        }
        $known = $this->references->lookupAll(array_filter($wanted));

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            [$owner, $type] = explode('/', $key, 2);
            $probe = $probes[$key] ?? null;
            $isCreation = null === $probe || (true !== $probe['ok'] && 404 === ($probe['status'] ?? null));
            if ($isCreation) {
                if (!\array_key_exists('associatedProductCodes', $item)) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['associatedProductCodes']];

                    continue;
                }
                if (!isset($known[Resource::Product->value][$owner])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'owner_not_found', 'ownerCode' => $owner];

                    continue;
                }
                if (!isset($known[Resource::ProductAssociationType->value][$type])) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'type_not_found', 'typeCode' => $type];

                    continue;
                }
            }
            $missing = array_values(array_filter(self::codesOf($item), static fn (string $code): bool => !isset($known[Resource::Product->value][$code])));
            if ([] !== $missing) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'associated_products_not_found', 'missing' => $missing];
            }
        }

        return ['failures' => $failures, 'references' => $known];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        [$owner, $type] = explode('/', (string) $this->keyOf($item), 2);
        $after = self::codesOf($item);
        if (null === $current) {
            return [
                'ownerCode' => ['before' => null, 'after' => $owner],
                'typeCode' => ['before' => null, 'after' => $type],
                'associatedProductCodes' => ['before' => null, 'after' => $after],
            ];
        }
        if (!\array_key_exists('associatedProductCodes', $item)) {
            return [];
        }
        $before = AdminPayload::codes($current['associatedProducts'] ?? []);
        $sortedBefore = $before;
        $sortedAfter = $after;
        sort($sortedBefore);
        sort($sortedAfter);

        return $sortedBefore === $sortedAfter ? [] : ['associatedProductCodes' => ['before' => $before, 'after' => $after]];
    }

    private function postBody(array $item, mixed $references): array
    {
        [$owner, $type] = explode('/', (string) $this->keyOf($item), 2);
        $known = self::knownOf($references);

        return [
            'owner' => $known[Resource::Product->value][$owner] ?? null,
            'type' => $known[Resource::ProductAssociationType->value][$type] ?? null,
            'associatedProducts' => self::productIris($item, $known),
        ];
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        $body['associatedProducts'] = self::productIris($item, self::knownOf($references));

        return $body;
    }

    /**
     * @param array<mixed>                         $item
     * @param array<string, array<string, string>> $known
     *
     * @return list<string>
     */
    private static function productIris(array $item, array $known): array
    {
        $iris = [];
        foreach (self::codesOf($item) as $code) {
            if (isset($known[Resource::Product->value][$code])) {
                $iris[] = $known[Resource::Product->value][$code];
            }
        }

        return $iris;
    }

    /**
     * @param array<mixed> $item
     *
     * @return list<string>
     */
    private static function codesOf(array $item): array
    {
        $codes = \is_array($item['associatedProductCodes'] ?? null) ? $item['associatedProductCodes'] : [];

        return array_values(array_unique(array_map(trim(...), array_filter($codes, 'is_string'))));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function knownOf(mixed $references): array
    {
        /** @var array<string, array<string, string>> $known */
        $known = \is_array($references) ? $references : [];

        return $known;
    }
}
