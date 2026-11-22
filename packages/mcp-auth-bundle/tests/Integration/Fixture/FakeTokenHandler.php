<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Integration\Fixture;

use Emagma\McpAuthBundle\McpGrantsTokenStore;
use Emagma\McpCoreBundle\Authorization\Grants;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * What a host's token handler does: validate the bearer, store the grants it carries.
 */
final readonly class FakeTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private McpGrantsTokenStore $store,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if ('good' !== $accessToken) {
            throw new BadCredentialsException('Invalid token.');
        }

        $this->store->store(Grants::fromStrings(['mcp:test:echo:read']));

        return new UserBadge('caller', static fn (string $id): InMemoryUser => new InMemoryUser($id, null));
    }
}
