<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\DependencyInjection\Compiler;

use Emagma\McpGuidelinesBundle\EmagmaMcpGuidelinesBundle;
use Emagma\McpGuidelinesBundle\Loader\MarkdownGuidelineLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Resolves the resources when the container is built (`@Bundle/...`, `%parameters%`), gives each one its loader
 * and its name, and stops the build on what cannot work: an unknown loader, a missing Markdown file, two
 * resources of the same name, an intent naming an unknown guideline.
 *
 * @internal
 */
final class GuidelinesPass implements CompilerPassInterface
{
    private const string RESOLVER = 'emagma_mcp_guidelines.resolver';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::RESOLVER)) {
            return;
        }
        $resolver = $container->getDefinition(self::RESOLVER);

        $loaders = [];
        foreach ($container->findTaggedServiceIds(EmagmaMcpGuidelinesBundle::LOADER_TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                if (\is_array($tag) && \is_string($tag['alias'] ?? null)) {
                    $loaders[$tag['alias']] = new Reference($id);
                }
            }
        }

        /** @var list<array{resource: string, loader: string|null, name: string|null}> $configured */
        $configured = $resolver->getArgument(1);
        $resources = [];
        foreach ($configured as $entry) {
            $resource = $this->path($container, $entry['resource']);
            $loader = $entry['loader'] ?? (str_ends_with($resource, '.md') ? MarkdownGuidelineLoader::ALIAS : null);
            if (null === $loader || !isset($loaders[$loader])) {
                throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.resources: no loader reads "%s"%s. Known loaders: %s.', $entry['resource'], null === $loader ? '' : ' (loader "' . $loader . '")', implode(', ', array_keys($loaders))));
            }
            if (MarkdownGuidelineLoader::ALIAS === $loader && !is_file($resource)) {
                throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.resources: the file "%s" does not exist.', $resource));
            }
            $name = $entry['name'] ?? basename($resource, '.md');
            if (1 !== preg_match('/^[a-z0-9_]+$/', $name)) {
                throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.resources: "%s" is not a guideline name (lowercase letters, digits, underscores); set `name`.', $name));
            }
            if (isset($resources[$name])) {
                throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.resources: two resources are named "%s": "%s" and "%s".', $name, $resources[$name]['resource'], $resource));
            }
            $resources[$name] = ['name' => $name, 'loader' => $loader, 'resource' => $resource];
        }

        /** @var array<string, array{description: string, guidelines: list<string>}> $intents */
        $intents = $resolver->getArgument(2);
        foreach ($intents as $intent => $definition) {
            $unknown = array_diff($definition['guidelines'], array_keys($resources));
            if ([] !== $unknown) {
                throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.intents.%s names unknown guidelines: %s. Known: %s.', $intent, implode(', ', $unknown), implode(', ', array_keys($resources))));
            }
        }

        $resolver->replaceArgument(0, ServiceLocatorTagPass::register($container, $loaders));
        $resolver->replaceArgument(1, array_values($resources));
    }

    private function path(ContainerBuilder $container, string $resource): string
    {
        $resource = $container->getParameterBag()->resolveValue($resource);
        \assert(\is_string($resource));
        if (!str_starts_with($resource, '@')) {
            return $resource;
        }

        [$bundle, $path] = explode('/', substr($resource, 1), 2) + [1 => ''];
        $metadata = $container->hasParameter('kernel.bundles_metadata') ? $container->getParameter('kernel.bundles_metadata') : [];
        $root = \is_array($metadata) && \is_array($metadata[$bundle] ?? null) ? ($metadata[$bundle]['path'] ?? null) : null;
        if (!\is_string($root)) {
            throw new InvalidArgumentException(\sprintf('emagma_mcp_guidelines.resources: the bundle "%s" of "%s" is not registered.', $bundle, $resource));
        }

        return $root . '/' . $path;
    }
}
