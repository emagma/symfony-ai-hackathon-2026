<?php

declare(strict_types=1);

namespace Emagma\McpAuthBundle\Tests\Integration;

use Emagma\McpAuthBundle\Tests\Integration\Fixture\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real compiled kernel: the firewall, the routes and the grants hold together as a host wires them.
 */
#[CoversNothing]
final class EndpointTest extends WebTestCase
{
    private const string POINTER = 'resource_metadata="http://localhost/.well-known/oauth-protected-resource"';

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::varDir());
    }

    public function testACallWithoutATokenIsPointedAtTheMetadata(): void
    {
        $client = self::createClient();
        $client->request('POST', '/_mcp');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer ' . self::POINTER);
    }

    public function testABadTokenIsPointedAtTheMetadataToo(): void
    {
        $client = self::createClient();
        $client->request('POST', '/_mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer bad']);

        self::assertResponseStatusCodeSame(401);
        $header = (string) $client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $header);
        self::assertStringContainsString(self::POINTER, $header);
    }

    public function testTheGrantsOfAGoodTokenAreThoseOfTheRequest(): void
    {
        $client = self::createClient();
        $client->request('POST', '/_mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer good']);

        self::assertResponseIsSuccessful();
        self::assertSame(['echo' => true, 'other' => false], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testTheMetadataIsPublicAtBothAddresses(): void
    {
        $client = self::createClient();

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/_mcp'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSame(
                [
                    'resource' => 'http://localhost/_mcp',
                    'authorization_servers' => ['https://idp.example.com/realms/test'],
                    'scopes_supported' => ['openid', 'profile', 'email'],
                    'bearer_methods_supported' => ['header'],
                ],
                json_decode((string) $client->getResponse()->getContent(), true),
            );
        }
    }
}
