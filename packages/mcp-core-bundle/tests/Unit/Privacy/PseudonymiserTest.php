<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Privacy;

use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pseudonymiser::class)]
final class PseudonymiserTest extends TestCase
{
    public function testTheTokenIsStableNormalisedAndKeyedByTheSecret(): void
    {
        $p = new Pseudonymiser('s3cret');

        $token = $p->tokenFor('email', 'Jean.Dupont@Example.com');
        self::assertMatchesRegularExpression('/^email:[0-9a-f]{8}$/', $token);
        self::assertSame($token, $p->tokenFor('email', 'jean.dupont@example.com '), 'case and spaces do not make another person');
        self::assertSame($p->tokenFor('phoneNumber', '+33 6 12 34 56 78'), $p->tokenFor('phoneNumber', '+33612345678'));
        self::assertNotSame($token, (new Pseudonymiser('other'))->tokenFor('email', 'jean.dupont@example.com'));
        self::assertNotSame($token, $p->tokenFor('email', 'ada@example.com'));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCP_PRIVACY_SECRET');

        new Pseudonymiser('');
    }

    public function testThePrefixSaysWhatKindOfIdentifierItStandsFor(): void
    {
        $p = new Pseudonymiser('s3cret');

        self::assertStringStartsWith('phone:', $p->tokenFor('phoneNumber', '0612345678'));
        self::assertStringStartsWith('login:', $p->tokenFor('username', 'jean.dupont'));
        self::assertStringStartsWith('email:', $p->tokenFor('contactEmail', 'x@y.z'));
        self::assertStringStartsWith('id:', $p->tokenFor('ssn', '123'));
    }
}
