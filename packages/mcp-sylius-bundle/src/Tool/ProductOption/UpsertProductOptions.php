<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductOption;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates product options in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_product_options',
    description: 'Create or update product options in one call, at most 50 items, matched on their code. name'
        . ' (required on a creation) targets the item locale (a creation copies it to every enabled locale);'
        . ' values is the desired {valueCode: label} set and, on an existing option, replaces it (omitted'
        . ' codes are deleted). Value codes are unique across ALL options of the shop: prefix them with the'
        . ' option (size_s, color_red). mode: upsert (default), create (already_exists on an existing code)'
        . ' or update (not_found on an unknown one). An item that changes nothing is unchanged and not'
        . ' written. One confirmation for the batch; one result per item. Tool-specific reason:'
        . ' fields_required.',
)]
#[Permission(resource: 'product_option', action: Action::Write)]
final readonly class UpsertProductOptions
{
    use BatchUpsertTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ProductOptionItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_product_options', Route::collection(Resource::ProductOption), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_option';
    }

    private function label(): string
    {
        return 'product options';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'locale'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        if (\array_key_exists('position', $item) && !\is_int($item['position'])) {
            return 'position is an integer';
        }
        if (\array_key_exists('values', $item)) {
            if (!\is_array($item['values'])) {
                return 'values is a {valueCode: label} map';
            }
            foreach ($item['values'] as $valueCode => $label) {
                if ('' === trim((string) $valueCode) || !\is_string($label)) {
                    return 'values is a {valueCode: label} map of strings';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array{locales: list<string>, values: array<string, array<string, array{iri: string, translations: array<mixed>}>>}}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $paths = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (!\array_key_exists('values', $item) || null === $probe || true !== $probe['ok']) {
                continue;
            }
            foreach (AdminPayload::strings($probe['body']['values'] ?? []) as $iri) {
                $paths[$iri] = $iri;
            }
        }

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (!\array_key_exists('name', $item) && (null === $probe || (true !== $probe['ok'] && 404 === ($probe['status'] ?? null)))) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['name']];
            }
        }
        $values = [];
        if ([] !== $paths) {
            $results = $this->client->getDecodedMany($paths);
            foreach ($itemsByKey as $key => $item) {
                $probe = $probes[$key] ?? null;
                if (!\array_key_exists('values', $item) || null === $probe || true !== $probe['ok']) {
                    continue;
                }
                $values[$key] = [];
                foreach (AdminPayload::strings($probe['body']['values'] ?? []) as $iri) {
                    $result = $results[$iri] ?? ['ok' => false, 'status' => null, 'error' => 'missing'];
                    $valueCode = true === $result['ok'] ? AdminPayload::text($result['body']['code'] ?? null) : '';
                    if ('' === $valueCode) {
                        // Rebuilding the set without a value the API cannot serve would delete it: the item fails.
                        $failures[$key] = ApiFailure::fromResult($result, ['action' => 'failed'], 'upstream_error');
                        unset($values[$key]);

                        break;
                    }
                    $values[$key][$valueCode] = [
                        'iri' => $iri,
                        'translations' => \is_array($result['body']['translations'] ?? null) ? $result['body']['translations'] : [],
                    ];
                }
            }
        }

        return ['failures' => $failures, 'references' => [
            'locales' => $this->enabledLocales($this->client, $this->defaultLocale),
            'values' => $values,
        ]];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $wantedValues = \is_array($item['values'] ?? null) ? $item['values'] : null;
        if (null === $current) {
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                $locale . '.name' => ['before' => null, 'after' => $item['name'] ?? null],
            ];
            if (\array_key_exists('position', $item)) {
                $diff['position'] = ['before' => null, 'after' => $item['position']];
            }
            if (null !== $wantedValues && [] !== $wantedValues) {
                $diff['values'] = ['before' => null, 'after' => array_keys($wantedValues)];
            }

            return $diff;
        }

        $diff = [];
        if (\is_string($item['name'] ?? null)) {
            $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $before = $translation['name'] ?? null;
            if ($before !== $item['name']) {
                $diff[$locale . '.name'] = ['before' => $before, 'after' => $item['name']];
            }
        }
        if (null !== $wantedValues) {
            $currentValues = $this->currentValuesOf($item, $references);
            foreach ($wantedValues as $valueCode => $label) {
                $before = self::valueLabel($currentValues[(string) $valueCode] ?? null, $locale);
                if ($before !== $label) {
                    $diff['value.' . $valueCode] = ['before' => $before, 'after' => $label];
                }
            }
            foreach ($currentValues as $valueCode => $value) {
                if (!\array_key_exists($valueCode, $wantedValues)) {
                    $diff['value.' . $valueCode] = ['before' => self::valueLabel($value, $locale), 'after' => null];
                }
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
        $body = [
            'code' => $item['code'],
            'translations' => self::fillTranslations($locales, ['name' => \is_string($item['name'] ?? null) ? $item['name'] : '']),
        ];
        if (\is_int($item['position'] ?? null)) {
            $body['position'] = $item['position'];
        }
        $values = \is_array($item['values'] ?? null) ? $item['values'] : [];
        if ([] !== $values) {
            $expanded = [];
            foreach ($values as $valueCode => $label) {
                $expanded[] = [
                    'code' => (string) $valueCode,
                    'translations' => self::fillTranslations($locales, ['value' => \is_string($label) ? $label : '']),
                ];
            }
            $body['values'] = $expanded;
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $body = $current;
        if (\is_string($item['name'] ?? null)) {
            $translations = \is_array($body['translations'] ?? null) ? $body['translations'] : [];
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $translation['name'] = $item['name'];
            $translations[$locale] = $translation;
            $body['translations'] = $translations;
        }
        if (\is_array($item['values'] ?? null)) {
            $body['values'] = $this->rebuildValues($this->currentValuesOf($item, $references), $locale, $item['values']);
        }

        return $body;
    }

    /**
     * Codes absent from the desired map are omitted, which deletes them (cascade).     *
     *
     * @param array<string, array{iri: string, translations: array<mixed>}> $currentValues
     * @param array<mixed>                                                          $values
     *
     * @return list<string|array<string, mixed>>
     */
    private function rebuildValues(array $currentValues, string $locale, array $values): array
    {
        $out = [];
        foreach ($values as $valueCode => $label) {
            $valueCode = (string) $valueCode;
            $label = \is_string($label) ? $label : '';
            $existing = $currentValues[$valueCode] ?? null;
            if (null === $existing) {
                $out[] = ['code' => $valueCode, 'translations' => [$locale => ['value' => $label, 'locale' => $locale]]];

                continue;
            }
            if (self::valueLabel($existing, $locale) === $label) {
                $out[] = $existing['iri'];

                continue;
            }
            $translations = $existing['translations'];
            if (\is_array($translations[$locale] ?? null)) {
                $translations[$locale]['value'] = $label;
            } else {
                $translations[$locale] = ['value' => $label, 'locale' => $locale];
            }
            $out[] = ['@id' => $existing['iri'], 'code' => $valueCode, 'translations' => $translations];
        }

        return $out;
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, array{iri: string, translations: array<mixed>}>
     */
    private function currentValuesOf(array $item, mixed $references): array
    {
        $key = \is_string($item['code'] ?? null) ? $item['code'] : '';
        $all = \is_array($references) && \is_array($references['values'] ?? null) ? $references['values'] : [];
        /** @var array<string, array{iri: string, translations: array<mixed>}> $values */
        $values = \is_array($all[$key] ?? null) ? $all[$key] : [];

        return $values;
    }

    /**
     * @param array{iri: string, translations: array<mixed>}|null $value
     */
    private static function valueLabel(?array $value, string $locale): ?string
    {
        return null === $value ? null : AdminPayload::translation($value['translations'], $locale, 'value');
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
