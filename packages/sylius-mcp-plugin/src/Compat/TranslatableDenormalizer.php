<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Compat;

use Sylius\Bundle\ApiBundle\Serializer\ContextKeys;
use Sylius\Resource\Model\TranslatableInterface;
use Sylius\Resource\Translation\Provider\TranslationLocaleProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Sylius's TranslatableDenormalizer as fixed in 2.1.13 and 2.2.4 (MIT, Sylius Sp. z o.o.): it leaves
 * the IRI string of a translatable relation alone. Put in place by FixTranslatableDenormalizerPass.
 *
 * @internal
 */
final class TranslatableDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    private const ALREADY_CALLED = 'sylius_translatable_denormalizer_already_called_for_%s';

    public function __construct(
        private TranslationLocaleProviderInterface $localeProvider,
    ) {
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $context[self::getAlreadyCalledKey($type)] = true;

        $defaultLocaleCode = $this->localeProvider->getDefaultLocaleCode();

        // Only an array reaches here: supportsDenormalization() says so.
        $data = \is_array($data) ? $data : [];
        $translations = \is_array($data['translations'] ?? null) ? $data['translations'] : [];
        if (!self::hasDefaultTranslation($translations, $defaultLocaleCode)) {
            $translations[$defaultLocaleCode] = ['locale' => $defaultLocaleCode];
            $data['translations'] = $translations;
        }

        return $this->denormalizer->denormalize($data, $type, $format, $context);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return
            \is_array($data) &&
            Request::METHOD_POST === ($context[ContextKeys::HTTP_REQUEST_METHOD_TYPE] ?? null) &&
            !isset($context[self::getAlreadyCalledKey($type)]) &&
            is_a($type, TranslatableInterface::class, true)
        ;
    }

    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [TranslatableInterface::class => false];
    }

    private static function getAlreadyCalledKey(string $class): string
    {
        return \sprintf(self::ALREADY_CALLED, $class);
    }

    /**
     * @param array<mixed> $translations
     */
    private static function hasDefaultTranslation(array $translations, string $defaultLocale): bool
    {
        return
            \is_array($translations[$defaultLocale] ?? null) &&
            $defaultLocale === ($translations[$defaultLocale]['locale'] ?? null)
        ;
    }
}
