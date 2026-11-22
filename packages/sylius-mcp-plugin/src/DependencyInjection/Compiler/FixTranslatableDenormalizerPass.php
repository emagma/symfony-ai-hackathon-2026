<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\DependencyInjection\Compiler;

use Composer\InstalledVersions;
use Emagma\SyliusMcpPlugin\Compat\TranslatableDenormalizer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs the fixed TranslatableDenormalizer on the Sylius versions whose own crashes on an IRI.
 *
 * @internal
 */
final class FixTranslatableDenormalizerPass implements CompilerPassInterface
{
    public const string SERVICE_ID = 'sylius_api.denormalizer.translatable';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SERVICE_ID) || !InstalledVersions::isInstalled('sylius/sylius')) {
            return;
        }
        $version = InstalledVersions::getVersion('sylius/sylius');
        if (null !== $version && self::needsTheFix($version)) {
            $container->getDefinition(self::SERVICE_ID)->setClass(TranslatableDenormalizer::class);
        }
    }

    /**
     * @param string $version Composer's normalized version (`2.0.18.0`), or a branch (`dev-…`, never fixed here)
     */
    public static function needsTheFix(string $version): bool
    {
        if (1 !== preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return false;
        }

        return version_compare($version, '2.1.13', '<') ||
            (version_compare($version, '2.2.0', '>=') && version_compare($version, '2.2.4', '<'));
    }
}
