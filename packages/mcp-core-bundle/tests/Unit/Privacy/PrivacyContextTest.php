<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Privacy;

use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrivacyContext::class)]
#[CoversClass(PrivacyMode::class)]
final class PrivacyContextTest extends TestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testUnconfiguredMeansNoCallerInTheLoopAndRendersInClear(): void
    {
        self::assertSame(PrivacyMode::Off, PrivacyContext::mode());
        self::assertFalse(PrivacyContext::hides());
        self::assertTrue(PrivacyContext::revealAllowed());
    }

    public function testPseudonymiseHidesUntilThisResponseIsRevealedAndResetForgetsIt(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        self::assertTrue(PrivacyContext::hides());
        self::assertStringStartsWith('email:', PrivacyContext::token('email', 'a@b.c'));

        PrivacyContext::revealThisResponse();
        self::assertFalse(PrivacyContext::hides());
        self::assertSame(PrivacyMode::Pseudonymise, PrivacyContext::mode(), 'the mode is still the client\'s');

        PrivacyContext::reset();
        self::assertSame(PrivacyMode::Off, PrivacyContext::mode());
    }

    public function testAClientMayBeDeniedRevealWhateverTheGrant(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'), revealAllowed: false);

        self::assertFalse(PrivacyContext::revealAllowed());
    }
}
