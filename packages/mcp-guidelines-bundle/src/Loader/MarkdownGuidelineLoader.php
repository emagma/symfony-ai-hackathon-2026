<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Loader;

use Emagma\McpGuidelinesBundle\GuidelineLoaderInterface;

/**
 * A Markdown file, read as it is: versioned and reviewed with the application, the recommended resource.
 *
 * @internal
 */
final readonly class MarkdownGuidelineLoader implements GuidelineLoaderInterface
{
    public const string ALIAS = 'markdown';

    public function load(string $resource): ?string
    {
        $content = is_file($resource) && is_readable($resource) ? file_get_contents($resource) : false;

        return false === $content || '' === trim($content) ? null : trim($content);
    }
}
