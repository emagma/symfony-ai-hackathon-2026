<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Line;

use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;

/**
 * The lines whose bundle is installed. A version none of them serves is refused on the first
 * request: a typo stops the server instead of serving the wrong routes.
 */
final readonly class SyliusLines
{
    /** @var array<string, SyliusLineInterface> minor version => its line */
    private array $byVersion;

    /**
     * @param iterable<SyliusLineInterface> $lines
     */
    public function __construct(iterable $lines)
    {
        $byVersion = [];
        foreach ($lines as $line) {
            foreach ($line->versions() as $version) {
                if (isset($byVersion[$version])) {
                    throw new \LogicException(\sprintf('Sylius %s is served by two lines: %s and %s.', $version, $byVersion[$version]::class, $line::class));
                }
                $byVersion[$version] = $line;
            }
        }
        uksort($byVersion, static fn (string $a, string $b): int => version_compare($a, $b));

        $this->byVersion = $byVersion;
    }

    /**
     * Minor versions, oldest first.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return array_map(strval(...), array_keys($this->byVersion));
    }

    public function supports(SyliusVersion $version): bool
    {
        return isset($this->byVersion[$version->line()]);
    }

    /**
     * @throws \InvalidArgumentException when no installed line serves it
     */
    public function configured(string $version): SyliusVersion
    {
        try {
            $parsed = new SyliusVersion($version);
        } catch (\InvalidArgumentException) {
            $parsed = null;
        }
        if (null === $parsed || !$this->supports($parsed)) {
            throw new \InvalidArgumentException([] === $this->byVersion
                ? \sprintf('MCP_SYLIUS_VERSION is "%s", but no Sylius line is installed: register the bundle of the shop\'s line (emagma/mcp-sylius-v2-bundle for Sylius 2.x).', $version)
                : \sprintf(
                    'MCP_SYLIUS_VERSION "%s" is not a supported Sylius version. Supported lines: %s (a patch may follow, e.g. 2.2.9).',
                    $version,
                    implode(', ', $this->versions()),
                ));
        }

        return $parsed;
    }

    /**
     * @throws \InvalidArgumentException when no installed line serves it
     */
    public function adapter(SyliusVersion $version, string $apiUrl): SyliusApiAdapterInterface
    {
        $line = $this->byVersion[$version->line()]
            ?? throw new \InvalidArgumentException(\sprintf('No installed Sylius line serves %s (installed: %s).', $version, implode(', ', $this->versions()) ?: 'none'));

        return $line->adapter($apiUrl);
    }
}
