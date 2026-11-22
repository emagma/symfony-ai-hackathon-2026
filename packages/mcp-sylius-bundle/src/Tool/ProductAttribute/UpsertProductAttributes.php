<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAttribute;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates product attributes in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_product_attributes',
    description: 'Create or update product attributes in one call, at most 50 items, matched on their code. A'
        . ' creation needs name and type (immutable afterwards) and copies the name to every enabled'
        . ' locale; name targets the item locale on an update. choices (select only) is the desired'
        . ' {key: label} set and, on an existing attribute, replaces it. mode: upsert (default), create'
        . ' (already_exists on an existing code) or update (not_found on an unknown one). An item that'
        . ' changes nothing is unchanged and not written. One confirmation for the batch; one result per'
        . ' item. Tool-specific reasons: type_required, type_immutable.',
)]
#[Permission(resource: 'product_attribute', action: Action::Write)]
final readonly class UpsertProductAttributes
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
        #[Schema(type: 'array', items: ProductAttributeItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_product_attributes', Route::collection(Resource::ProductAttribute), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_attribute';
    }

    private function label(): string
    {
        return 'product attributes';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'locale'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        if (\array_key_exists('type', $item) && !\in_array($item['type'], ProductAttributeItem::TYPES, true)) {
            return 'type is one of ' . implode(', ', ProductAttributeItem::TYPES);
        }
        foreach (['translatable', 'multiple'] as $field) {
            if (\array_key_exists($field, $item) && !\is_bool($item[$field])) {
                return $field . ' is a boolean';
            }
        }
        foreach (['min', 'max'] as $field) {
            if (\array_key_exists($field, $item) && !\is_int($item[$field])) {
                return $field . ' is an integer';
            }
        }
        if (\array_key_exists('choices', $item)) {
            if (!\is_array($item['choices'])) {
                return 'choices is a {key: label} map';
            }
            foreach ($item['choices'] as $key => $label) {
                if ('' === trim((string) $key) || !\is_string($label)) {
                    return 'choices is a {key: label} map of strings';
                }
            }
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
            $type = $item['type'] ?? null;
            if (null !== $probe && true === $probe['ok']) {
                $currentType = $probe['body']['type'] ?? null;
                if (null !== $type && $type !== $currentType) {
                    $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'type_immutable', 'type' => $currentType];
                }
            } elseif (null === $type && (null === $probe || 404 === ($probe['status'] ?? null))) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'type_required', 'allowed' => ProductAttributeItem::TYPES];
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
            $type = $item['type'] ?? null;
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                'type' => ['before' => null, 'after' => $type],
                $locale . '.name' => ['before' => null, 'after' => $item['name'] ?? null],
            ];
            if (\array_key_exists('translatable', $item)) {
                $diff['translatable'] = ['before' => null, 'after' => $item['translatable']];
            }
            if ('select' === $type) {
                foreach (['multiple', 'min', 'max'] as $field) {
                    if (\array_key_exists($field, $item)) {
                        $diff[$field] = ['before' => null, 'after' => $item[$field]];
                    }
                }
                $choices = \is_array($item['choices'] ?? null) ? $item['choices'] : [];
                if ([] !== $choices) {
                    $diff['choices'] = ['before' => null, 'after' => array_map(strval(...), array_keys($choices))];
                }
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
        if (\is_bool($item['translatable'] ?? null)) {
            $before = (bool) ($current['translatable'] ?? false);
            if ($before !== $item['translatable']) {
                $diff['translatable'] = ['before' => $before, 'after' => $item['translatable']];
            }
        }
        if ('select' === ($current['type'] ?? null)) {
            $configuration = self::configurationOf($current);
            foreach (['multiple', 'min', 'max'] as $field) {
                if (\array_key_exists($field, $item) && ($configuration[$field] ?? null) !== $item[$field]) {
                    $diff[$field] = ['before' => $configuration[$field] ?? null, 'after' => $item[$field]];
                }
            }
            if (\is_array($item['choices'] ?? null)) {
                $currentChoices = \is_array($configuration['choices'] ?? null) ? $configuration['choices'] : [];
                $beforeKeys = array_map(strval(...), array_keys($currentChoices));
                $afterKeys = array_map(strval(...), array_keys($item['choices']));
                if ($beforeKeys !== $afterKeys) {
                    $diff['choices'] = ['before' => $beforeKeys, 'after' => $afterKeys];
                }
                foreach ($item['choices'] as $key => $label) {
                    $labels = \is_array($currentChoices[$key] ?? null) ? $currentChoices[$key] : [];
                    $before = $labels[$locale] ?? null;
                    if (isset($currentChoices[$key]) && $before !== $label) {
                        $diff['choice.' . $key] = ['before' => $before, 'after' => $label];
                    }
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
        $type = $item['type'] ?? null;
        $body = [
            'code' => $item['code'],
            'type' => $type,
            'translatable' => \is_bool($item['translatable'] ?? null) ? $item['translatable'] : false,
            'translations' => self::fillTranslations($locales, ['name' => \is_string($item['name'] ?? null) ? $item['name'] : '']),
        ];
        if ('select' === $type) {
            $body['configuration'] = [
                'multiple' => \is_bool($item['multiple'] ?? null) ? $item['multiple'] : false,
                'min' => \is_int($item['min'] ?? null) ? $item['min'] : null,
                'max' => \is_int($item['max'] ?? null) ? $item['max'] : null,
                'choices' => self::expandChoices(\is_array($item['choices'] ?? null) ? $item['choices'] : [], $locale, []),
            ];
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
        if (\is_bool($item['translatable'] ?? null)) {
            $body['translatable'] = $item['translatable'];
        }
        if ('select' === ($current['type'] ?? null)) {
            $configuration = self::configurationOf($current);
            foreach (['multiple', 'min', 'max'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $configuration[$field] = $item[$field];
                }
            }
            if (\is_array($item['choices'] ?? null)) {
                $currentChoices = \is_array($configuration['choices'] ?? null) ? $configuration['choices'] : [];
                $configuration['choices'] = self::expandChoices($item['choices'], $locale, $currentChoices);
            }
            $body['configuration'] = $configuration;
        }

        return $body;
    }

    /**
     * `{key: label}` → `{key: {locale: label}}`; a kept key carries over its labels in the other locales.     *
     *
     * @param array<mixed> $choices
     * @param array<mixed> $currentChoices
     *
     * @return array<string, array<string, string>>
     */
    private static function expandChoices(array $choices, string $locale, array $currentChoices): array
    {
        $expanded = [];
        foreach ($choices as $key => $label) {
            $key = (string) $key;
            $labels = [];
            foreach (\is_array($currentChoices[$key] ?? null) ? $currentChoices[$key] : [] as $otherLocale => $otherLabel) {
                if (\is_string($otherLabel)) {
                    $labels[(string) $otherLocale] = $otherLabel;
                }
            }
            $labels[$locale] = \is_string($label) ? $label : '';
            $expanded[$key] = $labels;
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return array<mixed>
     */
    private static function configurationOf(array $current): array
    {
        return \is_array($current['configuration'] ?? null) ? $current['configuration'] : [];
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
