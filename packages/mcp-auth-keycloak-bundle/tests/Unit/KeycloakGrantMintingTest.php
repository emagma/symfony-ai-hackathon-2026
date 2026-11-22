<?php

declare(strict_types=1);

namespace Emagma\McpAuthKeycloakBundle\Tests\Unit;

use Emagma\McpAuthKeycloakBundle\KeycloakAccessTokenHandler;
use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\Permission;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class KeycloakGrantMintingTest extends TestCase
{
    public function testMcpRolesPassThroughVerbatim(): void
    {
        $grants = $this->mint(['mcp:sylius:product:read', 'mcp:sylius:order:read', 'offline_access']);

        self::assertTrue($grants->allowsAll([new Permission('product', Action::Read, 'sylius')]));
        self::assertTrue($grants->allowsAll([new Permission('order', Action::Read, 'sylius')]));
    }

    public function testNonMcpRolesGrantNothing(): void
    {
        $grants = $this->mint(['offline_access', 'uma_authorization', 'default-roles-sylius']);

        self::assertFalse($grants->allowsAll([new Permission('product', Action::Read, 'sylius')]));
    }

    public function testSyliusAdminAloneGrantsNothing(): void
    {
        // Keycloak expands the composite into mcp:* roles before the token gets here: alone, it carries no reach.
        $grants = $this->mint(['sylius-admin']);

        foreach (['product', 'order', 'customer', 'payment', 'address'] as $resource) {
            self::assertFalse(
                $grants->allowsAll([new Permission($resource, Action::Read)]),
                sprintf('sylius-admin must not imply %s:read on its own', $resource),
            );
        }
    }

    public function testSyliusMcpReaderAloneGrantsNothing(): void
    {
        $grants = $this->mint(['sylius-mcp-reader']);

        self::assertFalse($grants->allowsAll([new Permission('customer', Action::Read, 'sylius')]));
    }

    public function testAnAdminWhoseCompositeWasExpandedKeepsItsReach(): void
    {
        $grants = $this->mint(['sylius-admin', 'catalogue-write', 'mcp:sylius:product:write']);

        self::assertTrue($grants->allowsAll([new Permission('product', Action::Write, 'sylius')]));
        // write implies read.
        self::assertTrue($grants->allowsAll([new Permission('product', Action::Read, 'sylius')]));
        self::assertFalse($grants->allowsAll([new Permission('customer', Action::Read, 'sylius')]));
    }

    /**
     * @param list<string> $realmRoles
     */
    private function mint(array $realmRoles): Grants
    {
        $method = new \ReflectionMethod(KeycloakAccessTokenHandler::class, 'grantStrings');

        /** @var list<string> $strings */
        $strings = $method->invoke(null, $realmRoles);

        return Grants::fromStrings($strings);
    }
}
