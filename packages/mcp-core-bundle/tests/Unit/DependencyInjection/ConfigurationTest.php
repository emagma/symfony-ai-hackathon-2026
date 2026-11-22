<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\DependencyInjection;

use Emagma\McpCoreBundle\EmagmaMcpCoreBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

#[CoversClass(EmagmaMcpCoreBundle::class)]
final class ConfigurationTest extends TestCase
{
    public function testTheDefaultRefusesModelConfirmationOnEveryMutation(): void
    {
        $config = $this->process([]);

        self::assertSame(['write', 'delete'], $config['write_confirmation']['require_human']);
        self::assertSame([], $config['write_confirmation']['trusted_clients']);
        self::assertSame(0.0, $config['write_confirmation']['auto_cancel_threshold'], 'every cancel is a refusal unless the deployment says otherwise');
        self::assertSame([], $config['redaction']['personal_keys']);
    }

    public function testAClientWhoseUiGatesEveryCallCanBeTrustedByName(): void
    {
        $config = $this->process([['write_confirmation' => ['trusted_clients' => ['Anthropic/ClaudeAI', 'Anthropic/ClaudeAI']]]]);

        self::assertSame(['Anthropic/ClaudeAI'], $config['write_confirmation']['trusted_clients']);
        self::assertSame(['write', 'delete'], $config['write_confirmation']['require_human']);
    }

    public function testAnEmptyTrustedClientNameIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/non-empty name/');

        $this->process([['write_confirmation' => ['trusted_clients' => ['']]]]);
    }

    public function testADeploymentWithNoHumanCanEmptyTheList(): void
    {
        $config = $this->process([['write_confirmation' => ['require_human' => []]]]);

        self::assertSame([], $config['write_confirmation']['require_human']);
    }

    public function testATypoInAnActionIsRejectedAtCompileTimeRatherThanIgnored(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown MCP action "writes"/');

        $this->process([['write_confirmation' => ['require_human' => ['writes']]]]);
    }

    public function testDuplicatesAreCollapsed(): void
    {
        $config = $this->process([['write_confirmation' => ['require_human' => ['delete', 'delete']]]]);

        self::assertSame(['delete'], $config['write_confirmation']['require_human']);
    }

    public function testTheRedactionListsAcceptHostSpecificFields(): void
    {
        $config = $this->process([[
            'redaction' => [
                'personal_keys' => ['loyaltyCardNumber'],
                'secret_fragments' => ['hmac'],
            ],
        ]]);

        self::assertSame(['loyaltyCardNumber'], $config['redaction']['personal_keys']);
        self::assertSame(['hmac'], $config['redaction']['secret_fragments']);
    }

    public function testPrivacyIsPseudonymiseUnlessAClientIsNamed(): void
    {
        $privacy = $this->process([])['privacy'];
        self::assertSame('pseudonymise', $privacy['mode']);
        self::assertSame([], $privacy['clients']);
        self::assertSame('%env(default:kernel.secret:MCP_PRIVACY_SECRET)%', $privacy['secret']);

        $privacy = $this->process([['privacy' => ['clients' => [
            'mcp-internal' => 'off',
            'mcp-llm' => ['mode' => 'pseudonymise', 'reveal' => false],
        ]]]])['privacy'];
        self::assertSame(['mode' => 'off', 'reveal' => true], $privacy['clients']['mcp-internal']);
        self::assertSame(['mode' => 'pseudonymise', 'reveal' => false], $privacy['clients']['mcp-llm']);
    }

    public function testAnUnknownPrivacyModeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['privacy' => ['clients' => ['mcp-llm' => 'minimise']]]]);
    }

    public function testTheMarkerAndTheNoticeAreTheBundlesUnlessTheHostSaysOtherwise(): void
    {
        $config = $this->process([]);
        self::assertSame('__REDACTED__', $config['redaction']['marker']);
        self::assertNull($config['untrusted_content_notice']);

        $config = $this->process([['redaction' => ['marker' => '<hidden>'], 'untrusted_content_notice' => 'Written by customers.']]);
        self::assertSame('<hidden>', $config['redaction']['marker']);
        self::assertSame('Written by customers.', $config['untrusted_content_notice']);
    }

    public function testItAttachesToTheServerCalledDefaultUnlessNamed(): void
    {
        self::assertSame('default', $this->process([])['server']);
        self::assertSame('shop', $this->process([['server' => 'shop']])['server']);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        $extension = (new EmagmaMcpCoreBundle())->getContainerExtension();
        self::assertInstanceOf(Extension::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, $configs);
    }
}
