<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Sandbox;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Builds the hardened ini when the cache is warmed (cache:clear, cache:warmup, the build of the image), so that
 * no step pays for it.
 *
 * @internal
 */
final readonly class HardenedIniWarmer implements CacheWarmerInterface
{
    public function __construct(
        private HardenedIni $ini,
    ) {
    }

    public function isOptional(): bool
    {
        // Built on demand otherwise.
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->ini->path();

        return [];
    }
}
