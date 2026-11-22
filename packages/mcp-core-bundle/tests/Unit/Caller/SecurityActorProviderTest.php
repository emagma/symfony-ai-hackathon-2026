<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Caller;

use Emagma\McpCoreBundle\Caller\SecurityActorProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(SecurityActorProvider::class)]
final class SecurityActorProviderTest extends TestCase
{
    public function testACallerIsNamedByWhatItsUserCallsItsIdentifier(): void
    {
        $user = new class() implements UserInterface {
            public string $email = 'jean@example.com';

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'kc-7f3a9c21';
            }
        };

        self::assertSame('kc-7f3a9c21', (new SecurityActorProvider($this->security($user)))->actor());
    }

    public function testAnotherAuthenticatorsUserIsNamedByItsIdentifier(): void
    {
        self::assertSame('ops', (new SecurityActorProvider($this->security(new InMemoryUser('ops', null))))->actor());
    }

    public function testNoUserMeansNoActor(): void
    {
        self::assertNull((new SecurityActorProvider($this->security(null)))->actor());
    }

    private function security(?UserInterface $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }
}
