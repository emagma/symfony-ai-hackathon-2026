<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Privacy;

use Emagma\McpCoreBundle\Caller\McpCallerStore;
use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyContextConfigurator;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrivacyContextConfigurator::class)]
#[CoversClass(McpCallerStore::class)]
final class PrivacyContextConfiguratorTest extends TestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testAClientTheConfigurationNamesGetsItsModeTheOthersTheDefault(): void
    {
        $callers = new McpCallerStore();
        $configurator = new PrivacyContextConfigurator($callers, 'pseudonymise', [
            'mcp-internal' => ['mode' => 'off', 'reveal' => true],
            'mcp-llm' => ['mode' => 'pseudonymise', 'reveal' => false],
        ], 's3cret');

        $callers->store('mcp-internal');
        $configurator->apply();
        self::assertSame(PrivacyMode::Off, PrivacyContext::mode());

        $callers->store('mcp-llm');
        $configurator->apply();
        self::assertSame(PrivacyMode::Pseudonymise, PrivacyContext::mode());
        self::assertFalse(PrivacyContext::revealAllowed(), 'reveal: false holds whatever the grant');

        $callers->store('something-new');
        $configurator->apply();
        self::assertSame(PrivacyMode::Pseudonymise, PrivacyContext::mode(), 'an unlisted client gets the cautious default');
        self::assertTrue(PrivacyContext::revealAllowed());

        $callers->store(null);
        $configurator->apply();
        self::assertSame(PrivacyMode::Pseudonymise, PrivacyContext::mode(), 'a token without azp is an unlisted client');

        $configurator->reset();
        self::assertSame(PrivacyMode::Off, PrivacyContext::mode(), 'the kernel reset leaves no caller in the loop');
        $callers->reset();
        self::assertNull($callers->clientId());
    }
}
