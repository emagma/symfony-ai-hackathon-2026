<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The guidelines of `emagma_mcp_guidelines.resources`, read through their loader once per request or message (the
 * business may rewrite them in between), and those of each intent of `emagma_mcp_guidelines.intents`.
 *
 * @internal
 */
final class GuidelineResolver implements ResetInterface
{
    /** @var array<string, Guideline>|null */
    private ?array $guidelines = null;

    /**
     * @param ContainerInterface                                                  $loaders   alias => GuidelineLoaderInterface
     * @param list<array{name: string, loader: string, resource: string}>         $resources checked when the container is built
     * @param array<string, array{description: string, guidelines: list<string>}> $intents
     */
    public function __construct(
        private readonly ContainerInterface $loaders,
        private readonly array $resources,
        private readonly array $intents,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, Guideline> by name, in the order of the resources
     */
    public function all(): array
    {
        if (null !== $this->guidelines) {
            return $this->guidelines;
        }

        $guidelines = [];
        foreach ($this->resources as $resource) {
            $loader = $this->loaders->get($resource['loader']);
            \assert($loader instanceof GuidelineLoaderInterface);
            $content = $loader->load($resource['resource']);
            if (null === $content) {
                $this->logger?->warning('A guideline cannot be read: it is left out.', $resource);

                continue;
            }
            $guidelines[$resource['name']] = new Guideline($resource['name'], $content);
        }

        return $this->guidelines = $guidelines;
    }

    /**
     * The guidelines of these intents, each once, in the order of the intents then of their guidelines.
     *
     * @param list<string> $intents
     *
     * @return array{guidelines: list<Guideline>, unknown: list<string>}
     */
    public function forIntents(array $intents): array
    {
        $all = $this->all();
        $guidelines = [];
        $unknown = [];
        foreach ($intents as $intent) {
            if (!isset($this->intents[$intent])) {
                $unknown[] = $intent;

                continue;
            }
            foreach ($this->intents[$intent]['guidelines'] as $name) {
                if (isset($all[$name])) {
                    $guidelines[$name] = $all[$name];
                }
            }
        }

        return ['guidelines' => array_values($guidelines), 'unknown' => $unknown];
    }

    /**
     * @return array<string, array{description: string, guidelines: list<string>}>
     */
    public function intents(): array
    {
        return $this->intents;
    }

    /**
     * @return list<array{name: string, loader: string, resource: string}>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    public function reset(): void
    {
        $this->guidelines = null;
    }
}
