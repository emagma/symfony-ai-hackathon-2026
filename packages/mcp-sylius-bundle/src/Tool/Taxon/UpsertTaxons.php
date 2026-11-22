<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Taxon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates taxons in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_taxons',
    description: 'Create or update taxons in one call, at most 50 items, matched on their code. Translated'
        . ' fields (name, slug, description) target the item locale; a creation needs name and copies it'
        . ' to every enabled locale. parentCode must already exist (create parents in an earlier call).'
        . ' mode: upsert (default), create (already_exists on an existing code) or update (not_found on an'
        . ' unknown one). An item that changes nothing is unchanged and not written. One confirmation for'
        . ' the batch; one result per item. Tool-specific reasons: parent_not_found.',
)]
#[Permission(resource: 'taxon', action: Action::Write)]
final readonly class UpsertTaxons
{
    use BatchUpsertTrait;
    use TranslationsPayloadTrait;
    use WriteConfirmationTrait;

    public function __construct(
        private AdminApiClientInterface $client,
        private ReferenceResolver $references,
        private WriteJournalInterface $audit,
        private TaxonUpdatePayloadBuilder $payload,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: TaxonItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_taxons', Route::collection(Resource::Taxon), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'taxon';
    }

    private function label(): string
    {
        return 'taxons';
    }

    private function validateItem(array $item): ?string
    {
        foreach (['name', 'slug', 'description', 'locale', 'parentCode'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
        }
        if (\array_key_exists('position', $item) && !\is_int($item['position'])) {
            return 'position is an integer';
        }
        if (($item['parentCode'] ?? null) === ($item['code'] ?? null)) {
            return 'a taxon cannot be its own parent';
        }

        return null;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, mixed>     $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array{locales: list<string>, parents: array<string, string>}}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $parentCodes = [];
        foreach ($itemsByKey as $item) {
            if (\is_string($item['parentCode'] ?? null) && '' !== $item['parentCode']) {
                $parentCodes[$item['parentCode']] = true;
            }
        }
        $parents = [] === $parentCodes ? [] : ($this->references->lookupAll([Resource::Taxon->value => array_keys($parentCodes)])[Resource::Taxon->value] ?? []);

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $parentCode = $item['parentCode'] ?? null;
            if (\is_string($parentCode) && '' !== $parentCode && !isset($parents[$parentCode])) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'parent_not_found', 'parentCode' => $parentCode];
            }
        }

        return ['failures' => $failures, 'references' => [
            'locales' => $this->enabledLocales($this->client, $this->defaultLocale),
            'parents' => $parents,
        ]];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        if (null === $current) {
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                $locale . '.name' => ['before' => null, 'after' => $item['name'] ?? null],
            ];
            foreach (['slug', 'description'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$locale . '.' . $field] = ['before' => null, 'after' => $item[$field]];
                }
            }
            if (\array_key_exists('parentCode', $item)) {
                $diff['parent'] = ['before' => null, 'after' => $item['parentCode']];
            }
            foreach (['enabled', 'position'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }

            return $diff;
        }

        return $this->payload->computeDiff($current, $locale, $this->requested($item));
    }

    private function postBody(array $item, mixed $references): array
    {
        $locale = $this->localeOf($item);
        $translation = ['name' => \is_string($item['name'] ?? null) ? $item['name'] : ''];
        foreach (['slug', 'description'] as $field) {
            if (\is_string($item[$field] ?? null)) {
                $translation[$field] = $item[$field];
            }
        }
        $locales = \is_array($references) && \is_array($references['locales'] ?? null)
            ? array_values(array_filter($references['locales'], 'is_string'))
            : [$locale];
        if (!\in_array($locale, $locales, true)) {
            $locales = [$locale, ...$locales];
        }

        $body = [
            'code' => $item['code'],
            'translations' => self::fillTranslations($locales, $translation),
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
        ];
        $parentCode = $item['parentCode'] ?? null;
        $parents = \is_array($references) && \is_array($references['parents'] ?? null) ? $references['parents'] : [];
        if (\is_string($parentCode) && \is_string($parents[$parentCode] ?? null)) {
            $body['parent'] = $parents[$parentCode];
        }
        if (\is_int($item['position'] ?? null)) {
            $body['position'] = $item['position'];
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        return $this->payload->buildPutBody($current, $this->localeOf($item), $this->requested($item));
    }

    /**
     * @param array<mixed> $item
     *
     * @return array{name: ?string, slug: ?string, description: ?string, enabled: ?bool, position: ?int, parentCode: ?string}
     */
    private function requested(array $item): array
    {
        return [
            'name' => \is_string($item['name'] ?? null) ? $item['name'] : null,
            'slug' => \is_string($item['slug'] ?? null) ? $item['slug'] : null,
            'description' => \is_string($item['description'] ?? null) ? $item['description'] : null,
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : null,
            'position' => \is_int($item['position'] ?? null) ? $item['position'] : null,
            'parentCode' => \is_string($item['parentCode'] ?? null) ? $item['parentCode'] : null,
        ];
    }

    /**
     * @param array<mixed> $item
     */
    private function localeOf(array $item): string
    {
        return \is_string($item['locale'] ?? null) && '' !== $item['locale'] ? $item['locale'] : $this->defaultLocale;
    }
}
