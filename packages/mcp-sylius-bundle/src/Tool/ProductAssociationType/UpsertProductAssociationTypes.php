<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductAssociationType;

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
 * Creates or updates product association types in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_product_association_types',
    description: 'Create or update product association types in one call, at most 50 items, matched on their'
        . ' code. A creation needs name (copied to every enabled locale unless names gives per-locale'
        . ' wording); an update writes name in locale and each entry of names. mode: upsert (default),'
        . ' create (already_exists on an existing code) or update (not_found on an unknown one). An item'
        . ' that changes nothing is unchanged and not written. One confirmation for the batch; one result'
        . ' per item.',
)]
#[Permission(resource: 'product_association_type', action: Action::Write)]
final readonly class UpsertProductAssociationTypes
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
        #[Schema(type: 'array', items: ProductAssociationTypeItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_product_association_types', Route::collection(Resource::ProductAssociationType), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'product_association_type';
    }

    private function label(): string
    {
        return 'product association types';
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('name', $item) && (!\is_string($item['name']) || '' === trim($item['name']))) {
            return 'name is a non-empty string';
        }
        if (\array_key_exists('locale', $item) && (!\is_string($item['locale']) || '' === trim($item['locale']))) {
            return 'locale is a non-empty string';
        }
        if (\array_key_exists('names', $item)) {
            if (!\is_array($item['names'])) {
                return 'names is a {locale: name} map';
            }
            foreach ($item['names'] as $locale => $name) {
                if (!\is_string($locale) || !\is_string($name) || '' === trim($name)) {
                    return 'names is a {locale: name} map of non-empty strings';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, mixed>     $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: list<string>}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        return ['failures' => [], 'references' => $this->enabledLocales($this->client, $this->defaultLocale)];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $wanted = $this->wantedNames($item);
        if (null === $current) {
            $diff = ['code' => ['before' => null, 'after' => $item['code']]];
            foreach ($this->createTranslations($item, $references) as $locale => $translation) {
                $diff[$locale . '.name'] = ['before' => null, 'after' => $translation['name']];
            }

            return $diff;
        }

        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $diff = [];
        foreach ($wanted as $locale => $name) {
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $before = \is_string($translation['name'] ?? null) ? $translation['name'] : null;
            if ($before !== $name) {
                $diff[$locale . '.name'] = ['before' => $before, 'after' => $name];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        return ['code' => $item['code'], 'translations' => $this->createTranslations($item, $references)];
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        foreach ($this->wantedNames($item) as $locale => $name) {
            $translation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $translation['name'] = $name;
            // A new locale needs its own `locale` key; an existing one keeps the `@id` Doctrine updates by.
            if (!isset($translation['@id'])) {
                $translation['locale'] = $locale;
            }
            $translations[$locale] = $translation;
        }
        $body = $current;
        $body['translations'] = $translations;

        return $body;
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, string>
     */
    private function wantedNames(array $item): array
    {
        $wanted = [];
        if (\is_string($item['name'] ?? null)) {
            $wanted[$this->localeOf($item)] = $item['name'];
        }
        foreach (self::localeNameMap($item['names'] ?? null) as $locale => $name) {
            $wanted[$locale] = $name;
        }

        return $wanted;
    }

    /**
     * @param array<mixed> $item
     * @param mixed        $locales the batch's enabled locales
     *
     * @return array<string, array{name: string, locale: string}>
     */
    private function createTranslations(array $item, mixed $locales): array
    {
        $enabled = \is_array($locales) ? array_values(array_filter($locales, 'is_string')) : [$this->localeOf($item)];
        $primary = $this->localeOf($item);
        if (!\in_array($primary, $enabled, true)) {
            $enabled = [$primary, ...$enabled];
        }

        return self::fillNameTranslations($enabled, \is_string($item['name'] ?? null) ? $item['name'] : '', self::localeNameMap($item['names'] ?? null));
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
