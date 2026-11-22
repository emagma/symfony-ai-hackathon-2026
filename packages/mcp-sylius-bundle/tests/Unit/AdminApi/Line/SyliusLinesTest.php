<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Line;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Adapter\RootCouponsAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyliusLines::class)]
final class SyliusLinesTest extends TestCase
{
    private const string API = 'http://shop/api/v2/admin/';

    public function testTheVersionsServedAreThoseOfTheInstalledLinesOldestFirst(): void
    {
        self::assertSame(['1.14', '2.0', '2.1', '2.10'], $this->lines()->versions());
        self::assertSame([], (new SyliusLines([]))->versions());
    }

    public function testAVersionIsServedByTheLineThatListsItsMinorWhateverThePatch(): void
    {
        $lines = $this->lines();

        self::assertInstanceOf(RootCouponsAdapter::class, $lines->adapter(new SyliusVersion('1.14'), self::API));
        foreach (['2.0', '2.1.4', '2.10'] as $version) {
            self::assertInstanceOf(DefaultLayoutAdapter::class, $lines->adapter(new SyliusVersion($version), self::API));
        }
        self::assertSame('channels/WEB', $lines->adapter(new SyliusVersion('2.0'), 'https://example.com/shop/api')->pathFromIri('/shop/api/channels/WEB'), 'the adapter is given the API URL');
        self::assertTrue($lines->supports(new SyliusVersion('2.1.4')));
        self::assertFalse($lines->supports(new SyliusVersion('2.2')));
    }

    public function testTheConfiguredVersionIsParsedAndTrimmed(): void
    {
        self::assertSame('2.1.4', (string) $this->lines()->configured(' 2.1.4 '));
        self::assertSame('1.14', (string) $this->lines()->configured('1.14'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupported(): iterable
    {
        yield 'empty' => [''];
        yield 'a line nobody serves' => ['1.13'];
        yield 'a future line' => ['2.2'];
        yield 'a major only' => ['2'];
        yield 'a v prefix' => ['v2.1'];
    }

    #[DataProvider('unsupported')]
    public function testAVersionNoInstalledLineServesFailsLoudlyNamingTheSupportedLines(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MCP_SYLIUS_VERSION "' . $value . '" is not a supported Sylius version. Supported lines: 1.14, 2.0, 2.1, 2.10');

        $this->lines()->configured($value);
    }

    public function testAnAdapterIsRefusedForAVersionNoLineServes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No installed Sylius line serves 3.0 (installed: 1.14, 2.0, 2.1, 2.10)');

        $this->lines()->adapter(new SyliusVersion('3.0'), self::API);
    }

    public function testAServerWithoutAnyLineSaysWhichBundleToRegister(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no Sylius line is installed');

        (new SyliusLines([]))->configured('2.3');
    }

    public function testTwoLinesCannotServeTheSameVersion(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sylius 2.1 is served by two lines');

        new SyliusLines([$this->line(['2.0', '2.1'], DefaultLayoutAdapter::class), $this->line(['2.1'], RootCouponsAdapter::class)]);
    }

    private function lines(): SyliusLines
    {
        // Given newest first: the registry sorts, as versions and not as strings.
        return new SyliusLines([
            $this->line(['2.10', '2.0', '2.1'], DefaultLayoutAdapter::class),
            $this->line(['1.14'], RootCouponsAdapter::class),
        ]);
    }

    /**
     * @param list<string> $versions
     * @param class-string<SyliusApiAdapterInterface> $adapter
     */
    private function line(array $versions, string $adapter): SyliusLineInterface
    {
        return new readonly class($versions, $adapter) implements SyliusLineInterface {
            /**
             * @param list<string> $versions
             * @param class-string<SyliusApiAdapterInterface> $adapter
             */
            public function __construct(
                private array $versions,
                private string $adapter,
            ) {
            }

            public function versions(): array
            {
                return $this->versions;
            }

            public function adapter(string $apiUrl): SyliusApiAdapterInterface
            {
                return new ($this->adapter)($apiUrl);
            }
        };
    }
}
