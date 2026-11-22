<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\State;

use ApiPlatform\Metadata\Get;
use Composer\InstalledVersions;
use Emagma\SyliusMcpPlugin\ApiPlatform\State\CapabilitiesProvider;
use Emagma\SyliusMcpPlugin\ApiResource\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(CapabilitiesProvider::class)]
#[CoversClass(Capabilities::class)]
final class CapabilitiesProviderTest extends TestCase
{
    // Installed in the shop's vendor/, which is the test autoloader.
    private const string INSTALLED_PACKAGE = 'sylius/sylius';

    public function testNoDeclaredExtensionPublishesNothing(): void
    {
        $capabilities = (new CapabilitiesProvider([], new NullLogger()))->provide(new Get());

        self::assertSame([], $capabilities->extensions);
    }

    public function testADeclaredExtensionIsPublishedWithThePrettyVersionOfItsPackageWithoutTheLeadingV(): void
    {
        $capabilities = (new CapabilitiesProvider(['sylius_core' => self::INSTALLED_PACKAGE], new NullLogger()))->provide(new Get());

        $prettyVersion = InstalledVersions::getPrettyVersion(self::INSTALLED_PACKAGE);
        self::assertIsString($prettyVersion);
        self::assertSame(['sylius_core' => CapabilitiesProvider::publishedVersion($prettyVersion)], $capabilities->extensions);
        // sylius/sylius is a tag (`v2.3.0`): published without its `v`.
        self::assertMatchesRegularExpression('/^\d/', $capabilities->extensions['sylius_core']);
    }

    public function testTheSyliusVersionIsPublishedWithoutTheLeadingV(): void
    {
        $capabilities = (new CapabilitiesProvider([], new NullLogger()))->provide(new Get());

        $prettyVersion = InstalledVersions::getPrettyVersion(self::INSTALLED_PACKAGE);
        self::assertIsString($prettyVersion);
        self::assertSame(CapabilitiesProvider::publishedVersion($prettyVersion), $capabilities->sylius);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versions(): iterable
    {
        yield 'tag with a v' => ['v1.1.6', '1.1.6'];
        yield 'tag without' => ['1.1.6', '1.1.6'];
        yield 'pre-release' => ['v2.0.0-beta.1', '2.0.0-beta.1'];
        yield 'branch' => ['dev-main', 'dev-main'];
        yield 'a v not followed by a digit' => ['vendor-branch', 'vendor-branch'];
    }

    #[DataProvider('versions')]
    public function testThePublishedVersionDropsTheTagsLeadingV(string $prettyVersion, string $published): void
    {
        self::assertSame($published, CapabilitiesProvider::publishedVersion($prettyVersion));
    }

    public function testADeclaredExtensionWhosePackageIsMissingIsLeftOutAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('is not installed'),
            ['extension' => 'sylius_cms', 'package' => 'acme/not-installed'],
        );

        $capabilities = (new CapabilitiesProvider([
            'sylius_cms' => 'acme/not-installed',
            'sylius_core' => self::INSTALLED_PACKAGE,
        ], $logger))->provide(new Get());

        self::assertSame(['sylius_core'], array_keys($capabilities->extensions));
    }
}
