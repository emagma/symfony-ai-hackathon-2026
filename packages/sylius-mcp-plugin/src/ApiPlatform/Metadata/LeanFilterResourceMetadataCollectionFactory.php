<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Metadata;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;

/**
 * Appends the lean filters to the `GetCollection` operations of the Sylius admin API, without
 * editing vendor files. Idempotent. API Platform caches metadata: clear its pools after a change.
 *
 * @internal
 */
final readonly class LeanFilterResourceMetadataCollectionFactory implements ResourceMetadataCollectionFactoryInterface
{
    /**
     * @param array<class-string, array<string, list<string>>> $filtersByResource
     *        resource class => (operation uriTemplate => filter service ids)
     */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
        private array $filtersByResource,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);

        $filtersByUriTemplate = $this->filtersByResource[$resourceClass] ?? null;
        if (null === $filtersByUriTemplate) {
            return $collection;
        }

        foreach ($collection as $resource) {
            $operations = $resource->getOperations();
            if (null === $operations) {
                continue;
            }

            foreach ($operations as $name => $operation) {
                if (!$operation instanceof GetCollection) {
                    continue;
                }

                $uriTemplate = $operation->getUriTemplate();
                if (null === $uriTemplate) {
                    continue;
                }

                $additional = $filtersByUriTemplate[$uriTemplate] ?? null;
                if (null === $additional) {
                    continue;
                }

                $existing = $operation->getFilters() ?? [];
                $merged = array_values(array_unique([...$existing, ...$additional]));
                if ($merged === $existing) {
                    continue;
                }

                $operations->remove($name);
                $operations->add($name, $operation->withFilters($merged));
            }
        }

        return $collection;
    }
}
