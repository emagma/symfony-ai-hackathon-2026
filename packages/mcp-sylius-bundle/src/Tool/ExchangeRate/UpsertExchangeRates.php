<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ExchangeRate;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The resource has no code: the item key is the currency pair ("EUR/GBP"), matched over one GET of the collection.
 * Sylius keeps a single rate per pair, whichever way round.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_exchange_rates',
    description: 'Create or update exchange rates in one call, at most 50 items, matched on their currency'
        . ' pair (source, target - one rate per pair, in either direction). A creation needs ratio, which'
        . ' is also the only editable field. mode: upsert (default), create (already_exists on a known'
        . ' pair) or update (not_found on an unknown one). An item that changes nothing is unchanged and'
        . ' not written. One confirmation for the batch; one result per item, keyed "SRC/TGT".'
        . ' Tool-specific reasons: fields_required.',
)]
#[Permission(resource: 'exchange_rate', action: Action::Write)]
final readonly class UpsertExchangeRates
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const int FETCH_HARD_CAP = 1000;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ExchangeRateItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_exchange_rates', Route::collection(Resource::ExchangeRate), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'exchange_rate';
    }

    private function label(): string
    {
        return 'exchange rates';
    }

    /**
     * @param array<mixed> $item
     */
    private function keyOf(array $item): ?string
    {
        $source = $item['sourceCurrencyCode'] ?? null;
        $target = $item['targetCurrencyCode'] ?? null;
        if (!\is_string($source) || '' === trim($source) || !\is_string($target) || '' === trim($target)) {
            return null;
        }

        return strtoupper(trim($source)) . '/' . strtoupper(trim($target));
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('ratio', $item) && (!\is_int($item['ratio']) && !\is_float($item['ratio']) || $item['ratio'] <= 0)) {
            return 'ratio is a positive number';
        }
        [$source, $target] = explode('/', (string) $this->keyOf($item), 2) + [null, null];
        if ($source === $target) {
            return 'source and target currencies differ';
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
        try {
            $payload = $this->client->getDecoded($collection, ['page' => 1, 'itemsPerPage' => self::FETCH_HARD_CAP]);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();
            $failure = ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . $status];

            return array_fill_keys($keys, $failure);
        }

        $byPair = [];
        foreach (AdminPayload::members($payload) as $member) {
            $source = AdminPayload::code($member['sourceCurrency'] ?? null);
            $target = AdminPayload::code($member['targetCurrency'] ?? null);
            if (null === $source || null === $target) {
                continue;
            }
            $byPair[strtoupper($source) . '/' . strtoupper($target)] = $member;
            $byPair[strtoupper($target) . '/' . strtoupper($source)] = $member;
        }

        $probes = [];
        foreach ($keys as $key) {
            $probes[$key] = isset($byPair[$key])
                ? ['ok' => true, 'body' => $byPair[$key]]
                : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
        }

        return $probes;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function writePath(Route $collection, string $key, array $current): Route
    {
        return $collection->withKey((int) AdminPayload::int($current['id'] ?? null));
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: null}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            $isCreation = null === $probe || (true !== $probe['ok'] && 404 === ($probe['status'] ?? null));
            if ($isCreation && !\array_key_exists('ratio', $item)) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['ratio']];
            }
        }

        return ['failures' => $failures, 'references' => null];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        [$source, $target] = explode('/', (string) $this->keyOf($item), 2);
        $ratio = self::ratioOf($item);
        if (null === $current) {
            return [
                'sourceCurrency' => ['before' => null, 'after' => $source],
                'targetCurrency' => ['before' => null, 'after' => $target],
                'ratio' => ['before' => null, 'after' => $ratio],
            ];
        }
        $before = $current['ratio'] ?? null;
        $before = \is_int($before) || \is_float($before) ? (float) $before : null;

        return null === $ratio || $before === $ratio ? [] : ['ratio' => ['before' => $before, 'after' => $ratio]];
    }

    private function postBody(array $item, mixed $references): array
    {
        [$source, $target] = explode('/', (string) $this->keyOf($item), 2);

        return [
            'ratio' => self::ratioOf($item),
            'sourceCurrency' => $this->adapter->iri(Resource::Currency, $source),
            'targetCurrency' => $this->adapter->iri(Resource::Currency, $target),
        ];
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        $body['ratio'] = self::ratioOf($item);

        return $body;
    }

    /**
     * @param array<mixed> $item
     */
    private static function ratioOf(array $item): ?float
    {
        $ratio = $item['ratio'] ?? null;

        return \is_int($ratio) || \is_float($ratio) ? (float) $ratio : null;
    }
}
