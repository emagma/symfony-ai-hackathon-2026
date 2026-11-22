<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Symfony\Component\HttpFoundation\Request;

/**
 * In lean mode, swaps the normalization groups of a resource for its `mcp:*:lean` group. A resource
 * without a lean mapping keeps its full payload.
 *
 * @internal
 */
final class LeanContextBuilder implements SerializerContextBuilderInterface
{
    /**
     * @param array<string, string> $leanGroupByResource resource class => the
     *        lean group it exposes: the core entries (Configuration::CORE_LEAN_GROUPS)
     *        then the ones other bundles contribute through
     *        `emagma_sylius_mcp.lean.groups`. A resource class that is
     *        not in it is ignored: the original full context is returned.
     */
    public function __construct(
        private readonly SerializerContextBuilderInterface $decorated,
        private readonly LeanRequestDetector $leanRequestDetector,
        private readonly array $leanGroupByResource,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);

        if (!$normalization) {
            return $context;
        }

        if (!$this->leanRequestDetector->prefersLeanResponse($request)) {
            return $context;
        }

        /** @var class-string|null $resourceClass */
        $resourceClass = $context['resource_class'] ?? null;
        if (null === $resourceClass) {
            return $context;
        }

        $leanGroup = $this->leanGroupFor($resourceClass);
        if (null === $leanGroup) {
            return $context;
        }

        $context['groups'] = [$leanGroup];

        return $context;
    }

    /**
     * A shop overrides the Sylius models (`App\Entity\Product\Product extends
     * Sylius\Component\Core\Model\Product`), and API Platform reports the
     * shop's class as the resource. The serializer inherits the lean groups
     * from the parent mapping, so matching on the parent is all it takes.
     *
     * @param class-string $resourceClass
     */
    private function leanGroupFor(string $resourceClass): ?string
    {
        foreach ($this->leanGroupByResource as $modelClass => $leanGroup) {
            if (is_a($resourceClass, $modelClass, true)) {
                return $leanGroup;
            }
        }

        return null;
    }
}
