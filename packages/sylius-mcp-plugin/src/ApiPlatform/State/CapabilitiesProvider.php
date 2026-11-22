<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Composer\InstalledVersions;
use Emagma\SyliusMcpPlugin\ApiResource\Capabilities;
use Psr\Log\LoggerInterface;

/**
 * Provides `GET /admin/sylius-mcp/capabilities`: the MCP extensions the shop carries, each with the
 * installed version of its package, and the Sylius version. A package not installed is left out and logged.
 *
 * @implements ProviderInterface<Capabilities>
 *
 * @internal
 */
final readonly class CapabilitiesProvider implements ProviderInterface
{
    /**
     * @param array<string, string> $extensions extension name => Composer package
     */
    public function __construct(
        private array $extensions,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Capabilities
    {
        $published = [];
        foreach ($this->extensions as $name => $package) {
            // getPrettyVersion() throws on a package that is not installed,
            // and answers null on one that is only replaced or provided.
            $version = InstalledVersions::isInstalled($package)
                ? InstalledVersions::getPrettyVersion($package)
                : null;

            if (null === $version) {
                $this->logger->warning('MCP extension "{extension}" is declared but its package "{package}" is not installed: it is not published.', [
                    'extension' => $name,
                    'package' => $package,
                ]);

                continue;
            }

            $published[$name] = self::publishedVersion($version);
        }

        $sylius = InstalledVersions::isInstalled('sylius/sylius')
            ? InstalledVersions::getPrettyVersion('sylius/sylius')
            : null;

        return new Capabilities($published, null === $sylius ? null : self::publishedVersion($sylius));
    }

    public static function publishedVersion(string $prettyVersion): string
    {
        return preg_replace('/^v(?=\d)/', '', $prettyVersion) ?? $prettyVersion;
    }
}
