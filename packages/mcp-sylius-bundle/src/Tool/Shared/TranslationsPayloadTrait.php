<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * Sylius validates a translatable entity against EVERY enabled locale: a create that sends one
 * locale fails on a multi-locale shop. So each enabled locale gets an entry, the primary's by default.
 *
 * @internal
 */
trait TranslationsPayloadTrait
{
    /**
     * Falls back to the primary alone when the locales cannot be read: the create may still succeed.
     *
     * @return list<string>
     */
    private function enabledLocales(AdminApiClientInterface $client, string $primaryLocale): array
    {
        try {
            $payload = $client->getDecoded(Route::collection(Resource::Locale), ['itemsPerPage' => 100]);
        } catch (\Throwable) {
            return [$primaryLocale];
        }

        $codes = [];
        foreach (AdminPayload::members($payload) as $member) {
            $code = $member['code'] ?? null;
            if (\is_string($code) && '' !== $code) {
                $codes[] = $code;
            }
        }

        if ([] === $codes) {
            return [$primaryLocale];
        }

        // Primary first so it wins any ordering the caller relies on.
        return array_values(array_unique([$primaryLocale, ...$codes]));
    }

    /**
     * @param list<string>         $locales
     * @param array<string, mixed> $translation the payload for the primary locale
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fillTranslations(array $locales, array $translation): array
    {
        unset($translation['locale']);

        $translations = [];
        foreach ($locales as $locale) {
            $translations[$locale] = $translation + ['locale' => $locale];
        }

        return $translations;
    }

    /**
     * @param list<string>          $locales
     * @param array<string, string> $overrides locale => name
     *
     * @return array<string, array{name: string, locale: string}>
     */
    private static function fillNameTranslations(array $locales, string $name, array $overrides = []): array
    {
        /** @var array<string, array{name: string, locale: string}> $translations */
        $translations = self::fillTranslations($locales, ['name' => $name]);

        foreach ($overrides as $locale => $override) {
            if (isset($translations[$locale])) {
                $translations[$locale]['name'] = $override;
            }
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private static function localeNameMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $locale => $name) {
            if (\is_string($locale) && '' !== $locale && \is_string($name) && '' !== trim($name)) {
                $out[$locale] = trim($name);
            }
        }

        return $out;
    }
}
