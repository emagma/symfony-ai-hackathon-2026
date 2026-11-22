<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Integration\Fixture;

use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The handler of the firewall that guards the MCP endpoint, as a host names it: every token is a user.
 */
final class StubTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        return new UserBadge($accessToken);
    }
}
