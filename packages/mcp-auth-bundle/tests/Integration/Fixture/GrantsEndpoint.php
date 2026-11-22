<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Integration\Fixture;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Stands in for the MCP endpoint: says what the grants of the request allow.
 */
final readonly class GrantsEndpoint
{
    public function __construct(
        private GrantsProviderInterface $grants,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $grants = $this->grants->grants();

        return new JsonResponse([
            'echo' => $grants->allows(new Permission('echo', Action::Read, 'test')),
            'other' => $grants->allows(new Permission('other', Action::Read, 'test')),
        ]);
    }
}
