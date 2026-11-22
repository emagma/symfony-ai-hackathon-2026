<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Taxon;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;

/**
 * @internal
 */
final class TaxonUpdatePayloadBuilder
{
    public function __construct(
        private readonly SyliusApiAdapterInterface $adapter,
    ) {
    }

    /**
     * @param array<string, mixed>                                                              $current
     * @param array{name: ?string, slug: ?string, description: ?string, enabled: ?bool, position: ?int, parentCode?: ?string} $requested
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function computeDiff(array $current, string $locale, array $requested): array
    {
        $diff = [];
        $translations = \is_array($current['translations'] ?? null) ? $current['translations'] : [];
        $currentTranslation = \is_array($translations[$locale] ?? null) ? $translations[$locale] : [];

        foreach (['name', 'slug', 'description'] as $field) {
            if (null === $requested[$field]) {
                continue;
            }
            $before = $currentTranslation[$field] ?? null;
            if ($before === $requested[$field]) {
                continue;
            }
            $diff[$locale . '.' . $field] = ['before' => $before, 'after' => $requested[$field]];
        }
        if (null !== $requested['enabled'] && (bool) ($current['enabled'] ?? false) !== $requested['enabled']) {
            $diff['enabled'] = ['before' => (bool) ($current['enabled'] ?? false), 'after' => $requested['enabled']];
        }
        if (null !== $requested['position'] && ($current['position'] ?? null) !== $requested['position']) {
            $diff['position'] = ['before' => $current['position'] ?? null, 'after' => $requested['position']];
        }

        $parentCode = $requested['parentCode'] ?? null;
        if (null !== $parentCode) {
            $before = \is_string($current['parent'] ?? null) ? basename($current['parent']) : null;
            if ($before !== $parentCode) {
                $diff['parent'] = ['before' => $before, 'after' => $parentCode];
            }
        }

        return $diff;
    }

    /**
     * @param array<string, mixed>                                                              $current
     * @param array{name: ?string, slug: ?string, description: ?string, enabled: ?bool, position: ?int, parentCode?: ?string} $requested
     *
     * @return array<string, mixed>
     */
    public function buildPutBody(array $current, string $locale, array $requested): array
    {
        // The Sylius admin PUT needs the full current body: a delta rebuilds the translations from scratch
        // and trips the unique-slug validator. The @id markers let Doctrine match existing rows.
        $body = $current;
        if (!\is_array($body['translations'] ?? null)) {
            $body['translations'] = [];
        }
        if (!\is_array($body['translations'][$locale] ?? null)) {
            $body['translations'][$locale] = [];
        }
        foreach (['name', 'slug', 'description'] as $field) {
            if (null !== $requested[$field]) {
                $body['translations'][$locale][$field] = $requested[$field];
            }
        }
        if (null !== $requested['enabled']) {
            $body['enabled'] = $requested['enabled'];
        }
        if (null !== $requested['position']) {
            $body['position'] = $requested['position'];
        }
        $parentCode = $requested['parentCode'] ?? null;
        if (null !== $parentCode) {
            $body['parent'] = $this->adapter->iri(Resource::Taxon, $parentCode);
        }

        return $body;
    }
}
