<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Serializer;

use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Sylius\Resource\Model\TranslatableInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * In lean mode, when the caller named a locale, keeps that locale in `translations` (the first one
 * when it is missing, so a name never comes back empty).
 *
 * @internal
 */
final class LeanTranslationTrimNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = '__mcp_lean_translation_trim_called';

    /** JSON-LD envelope on a translation: addressable in theory, never addressed. */
    private const array STRIPPED_KEYS = ['@id', '@type'];

    public function __construct(
        private readonly LeanRequestDetector $leanRequestDetector,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return \ArrayObject<string, mixed>|array<array-key, mixed>|string|int|float|bool|null
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if (!\is_array($normalized) || !\is_array($normalized['translations'] ?? null)) {
            return $normalized;
        }

        $normalized['translations'] = self::trim(
            $normalized['translations'],
            $this->leanRequestDetector->requestedLocale(),
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return !isset($context[self::ALREADY_CALLED]) &&
            $data instanceof TranslatableInterface &&
            $this->leanRequestDetector->prefersLeanResponse();
    }

    /**
     * @return array<class-string|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        // Not cacheable by type: the decision depends on the request's Prefer
        // header, not on the class being normalized.
        return [TranslatableInterface::class => false];
    }

    /**
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    private static function trim(array $translations, ?string $locale): array
    {
        // No locale announced, nothing to narrow to. Keeping an arbitrary one
        // would silently change what a lean consumer receives - a caller that
        // did not say which locale it reads has not agreed to lose the others.
        if (null === $locale || [] === $translations) {
            return $translations;
        }

        $keptLocale = \array_key_exists($locale, $translations)
            ? $locale
            // Mirrors the tools' own fallback rather than emptying the field:
            // a product translated only in German still has a name.
            : array_key_first($translations);

        $kept = $translations[$keptLocale];
        if (!\is_array($kept)) {
            return [$keptLocale => $kept];
        }

        foreach (self::STRIPPED_KEYS as $key) {
            unset($kept[$key]);
        }

        return [$keptLocale => $kept];
    }
}
