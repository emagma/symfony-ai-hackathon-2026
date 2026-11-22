<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

/**
 * Typed reads of a decoded Admin API payload (untyped JSON-LD), in one place.
 *
 * @internal
 */
final class AdminPayload
{
    private function __construct()
    {
    }

    /**
     * Falls back to the first locale carrying the field: an enabled locale may be left untranslated.
     */
    public static function translation(mixed $translations, string $locale, string $field): ?string
    {
        if (!\is_array($translations)) {
            return null;
        }
        if (isset($translations[$locale]) && \is_array($translations[$locale]) &&
            isset($translations[$locale][$field]) && \is_string($translations[$locale][$field])
        ) {
            return $translations[$locale][$field];
        }
        foreach ($translations as $translation) {
            if (\is_array($translation) && isset($translation[$field]) && \is_string($translation[$field])) {
                return $translation[$field];
            }
        }

        return null;
    }

    /**
     * `/api/v2/admin/channels/WEB` becomes `WEB`.
     */
    public static function code(mixed $iri): ?string
    {
        return \is_string($iri) && '' !== $iri ? basename($iri) : null;
    }

    /**
     * @return list<string>
     */
    public static function codes(mixed $iris): array
    {
        return array_map(basename(...), self::strings($iris));
    }

    /**
     * A related resource arrives inlined (`{code: "FR"}`) or as an IRI, depending on the serialization group.
     */
    public static function codeOf(mixed $entry): ?string
    {
        if (\is_array($entry)) {
            return \is_string($entry['code'] ?? null) ? $entry['code'] : null;
        }

        return self::code($entry);
    }

    /**
     * For a resource keyed by a database id; falls back to the tail of `@id`.
     *
     * @param array<string, mixed> $payload
     */
    public static function identifier(array $payload): ?string
    {
        $id = $payload['id'] ?? null;
        if (\is_int($id) || \is_string($id)) {
            return (string) $id;
        }

        return self::code($payload['@id'] ?? null);
    }

    /**
     * API Platform 4 answers `member`, earlier versions (and some Sylius contexts) `hydra:member`.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function members(array $payload): array
    {
        $members = $payload['member'] ?? $payload['hydra:member'] ?? [];
        if (!\is_array($members)) {
            return [];
        }

        $out = [];
        foreach ($members as $member) {
            if (\is_array($member)) {
                $out[] = $member;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function totalItems(array $payload, int $fallback): int
    {
        $total = $payload['totalItems'] ?? $payload['hydra:totalItems'] ?? null;

        return \is_int($total) || \is_string($total) ? (int) $total : $fallback;
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }

    /**
     * Not `(string) ($x ?? '')`, which turns an unexpected array into the literal "Array".
     */
    public static function text(mixed $value, string $default = ''): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_int($value) || \is_float($value) ? (string) $value : $default;
    }

    public static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    /**
     * An int is accepted: a JSON price of `10` decodes to one.
     */
    public static function float(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    public static function count(mixed $collection): int
    {
        return \is_array($collection) ? \count($collection) : 0;
    }
}
