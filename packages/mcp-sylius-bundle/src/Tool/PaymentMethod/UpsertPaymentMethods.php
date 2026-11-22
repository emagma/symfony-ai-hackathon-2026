<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PaymentMethod;

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
use Emagma\McpSyliusBundle\Tool\Shared\SortablePositionNotice;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * factoryName and gatewayName are create-time only: the shop keeps the gateway a method was created with.
 * gatewayConfig lands in `gatewayConfig.config` and replaces it.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_payment_methods',
    description: 'Create or update payment methods in one call, at most 50 items, matched on their code. A'
        . ' creation needs name and channelCodes (factoryName defaults to offline) and copies the'
        . ' translated fields to every enabled locale; on an update they target the item locale,'
        . ' channelCodes and gatewayConfig replace the current ones, factoryName and gatewayName are'
        . ' ignored. mode: upsert (default), create (already_exists on an existing code) or update'
        . ' (not_found on an unknown one). An item that changes nothing is unchanged and not written. One'
        . ' confirmation for the batch; one result per item. Tool-specific reasons: fields_required.'
        . SortablePositionNotice::POSITION,
)]
#[Permission(resource: 'payment_method', action: Action::Write)]
final readonly class UpsertPaymentMethods
{
    use BatchUpsertTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    private const array CREATE_FIELDS = ['name', 'channelCodes'];

    private const array TRANSLATED = ['name', 'description', 'instructions'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
        private SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: PaymentMethodItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_payment_methods', Route::collection(Resource::PaymentMethod), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'payment_method';
    }

    private function label(): string
    {
        return 'payment methods';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'locale', 'factoryName', 'gatewayName'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        foreach (['description', 'instructions'] as $field) {
            if (\array_key_exists($field, $item) && !\is_string($item[$field])) {
                return $field . ' is a string';
            }
        }
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
        }
        if (\array_key_exists('position', $item) && !\is_int($item['position'])) {
            return 'position is an integer';
        }
        if (\array_key_exists('channelCodes', $item)) {
            if (!\is_array($item['channelCodes']) || [] === $item['channelCodes']) {
                return 'channelCodes is a non-empty list of channel codes';
            }
            foreach ($item['channelCodes'] as $channelCode) {
                if (!\is_string($channelCode) || '' === trim($channelCode)) {
                    return 'channelCodes is a non-empty list of channel codes';
                }
            }
        }
        if (\array_key_exists('gatewayConfig', $item) && !\is_array($item['gatewayConfig'])) {
            return 'gatewayConfig is an object';
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array{locales: list<string>}}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (null !== $probe && (true === $probe['ok'] || 404 !== ($probe['status'] ?? null))) {
                continue;
            }
            $missing = array_values(array_filter(self::CREATE_FIELDS, static fn (string $field): bool => !\array_key_exists($field, $item)));
            if ([] !== $missing) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => $missing];
            }
        }

        return ['failures' => $failures, 'references' => [
            'locales' => $this->enabledLocales($this->client, $this->defaultLocale),
        ]];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        if (null === $current) {
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                $locale . '.name' => ['before' => null, 'after' => $item['name'] ?? null],
                'channels' => ['before' => null, 'after' => $item['channelCodes'] ?? null],
                'factoryName' => ['before' => null, 'after' => \is_string($item['factoryName'] ?? null) ? $item['factoryName'] : 'offline'],
                'enabled' => ['before' => null, 'after' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true],
            ];
            foreach (['description', 'instructions'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$locale . '.' . $field] = ['before' => null, 'after' => $item[$field]];
                }
            }
            if (\array_key_exists('gatewayConfig', $item)) {
                $diff['gatewayConfig.config'] = ['before' => null, 'after' => $item['gatewayConfig']];
            }
            if (\array_key_exists('position', $item)) {
                $diff['position'] = ['before' => null, 'after' => $item['position']];
            }

            return $diff;
        }

        $diff = [];
        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
        foreach (self::TRANSLATED as $field) {
            if (\is_string($item[$field] ?? null)) {
                $before = \is_string($translation[$field] ?? null) ? $translation[$field] : null;
                if ($before !== $item[$field]) {
                    $diff[$locale . '.' . $field] = ['before' => $before, 'after' => $item[$field]];
                }
            }
        }
        if (\is_array($item['channelCodes'] ?? null)) {
            $before = AdminPayload::codes($current['channels'] ?? []);
            $sortedBefore = $before;
            sort($sortedBefore);
            $sortedAfter = array_values($item['channelCodes']);
            sort($sortedAfter);
            if ($sortedBefore !== $sortedAfter) {
                $diff['channels'] = ['before' => $before, 'after' => array_values($item['channelCodes'])];
            }
        }
        if (\is_array($item['gatewayConfig'] ?? null)) {
            $gateway = \is_array($current['gatewayConfig'] ?? null) ? $current['gatewayConfig'] : [];
            $before = $gateway['config'] ?? [];
            if ($before !== $item['gatewayConfig']) {
                $diff['gatewayConfig.config'] = ['before' => $before, 'after' => $item['gatewayConfig']];
            }
        }
        if (\is_bool($item['enabled'] ?? null)) {
            $before = (bool) ($current['enabled'] ?? false);
            if ($before !== $item['enabled']) {
                $diff['enabled'] = ['before' => $before, 'after' => $item['enabled']];
            }
        }
        if (\is_int($item['position'] ?? null)) {
            $before = AdminPayload::int($current['position'] ?? null);
            if ($before !== $item['position']) {
                $diff['position'] = ['before' => $before, 'after' => $item['position']];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $locales = \is_array($references) && \is_array($references['locales'] ?? null)
            ? array_values(array_filter($references['locales'], 'is_string'))
            : [$locale];
        if (!\in_array($locale, $locales, true)) {
            $locales = [$locale, ...$locales];
        }
        $name = \is_string($item['name'] ?? null) ? $item['name'] : '';
        $translation = ['name' => $name];
        foreach (['description', 'instructions'] as $field) {
            if (\is_string($item[$field] ?? null)) {
                $translation[$field] = $item[$field];
            }
        }
        $body = [
            'code' => $item['code'],
            'channels' => $this->channelIris(\is_array($item['channelCodes'] ?? null) ? $item['channelCodes'] : []),
            'gatewayConfig' => [
                'factoryName' => \is_string($item['factoryName'] ?? null) ? $item['factoryName'] : 'offline',
                'gatewayName' => \is_string($item['gatewayName'] ?? null) ? $item['gatewayName'] : $name,
                'config' => \is_array($item['gatewayConfig'] ?? null) ? $item['gatewayConfig'] : [],
            ],
            'translations' => self::fillTranslations($locales, $translation),
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
        ];
        if (\is_int($item['position'] ?? null)) {
            $body['position'] = $item['position'];
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $body = $current;
        $translations = \is_array($body['translations'] ?? null) ? $body['translations'] : [];
        $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
        $translated = false;
        foreach (self::TRANSLATED as $field) {
            if (\is_string($item[$field] ?? null)) {
                $translation[$field] = $item[$field];
                $translated = true;
            }
        }
        if ($translated) {
            if (!isset($translation['@id'])) {
                $translation['locale'] = $locale;
            }
            $translations[$locale] = $translation;
            $body['translations'] = $translations;
        }
        if (\is_array($item['channelCodes'] ?? null)) {
            $body['channels'] = $this->channelIris($item['channelCodes']);
        }
        if (\is_array($item['gatewayConfig'] ?? null)) {
            $gateway = \is_array($body['gatewayConfig'] ?? null) ? $body['gatewayConfig'] : [];
            $gateway['config'] = $item['gatewayConfig'];
            $body['gatewayConfig'] = $gateway;
        }
        if (\is_bool($item['enabled'] ?? null)) {
            $body['enabled'] = $item['enabled'];
        }
        if (\is_int($item['position'] ?? null)) {
            $body['position'] = $item['position'];
        }

        return $body;
    }

    /**
     * @param array<mixed> $channelCodes
     *
     * @return list<string>
     */
    private function channelIris(array $channelCodes): array
    {
        $iris = [];
        foreach ($channelCodes as $channelCode) {
            if (\is_string($channelCode)) {
                $iris[] = $this->adapter->iri(Resource::Channel, $channelCode);
            }
        }

        return $iris;
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
