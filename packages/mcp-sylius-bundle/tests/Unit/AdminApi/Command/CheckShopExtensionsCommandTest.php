<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Command;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\CapabilitiesReader;
use Emagma\McpSyliusBundle\AdminApi\Command\CheckShopExtensionsCommand;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CheckShopExtensionsCommand::class)]
final class CheckShopExtensionsCommandTest extends TestCase
{
    public function testAnActiveExtensionThatTheShopPublishesPasses(): void
    {
        [$status, $display] = $this->check('sylius_cms', ['@type' => 'SyliusMcpCapabilities', 'extensions' => ['sylius_cms' => '1.1.6']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('sylius_cms', $display);
        self::assertStringNotContainsString('WARNING', $display);
    }

    public function testNothingActiveAndNothingPublishedPasses(): void
    {
        [$status, $display] = $this->check('', ['extensions' => []]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No shop extension is active', $display);
    }

    public function testAnActiveExtensionMissingFromTheShopFails(): void
    {
        [$status, $display] = $this->check('sylius_cms', ['extensions' => []]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('[ERROR]', $display);
        self::assertStringContainsString('sylius_cms', $display);
    }

    public function testAPublishedExtensionInactiveHereOnlyWarns(): void
    {
        [$status, $display] = $this->check('', ['extensions' => ['sylius_cms' => '1.1.6']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('[WARNING]', $display);
        self::assertStringContainsString('"sylius_cms" (1.1.6)', $display);
    }

    public function testAnOldPluginIsAnErrorOnlyWhenSomethingIsActive(): void
    {
        [$status] = $this->check('', $this->clientException(404));
        self::assertSame(Command::SUCCESS, $status);

        [$status, $display] = $this->check('sylius_cms', $this->clientException(404));
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('no capabilities endpoint', $display);
    }

    public function testAnotherShopErrorFails(): void
    {
        [$status, $display] = $this->check('', $this->clientException(500));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('500', $display);
    }

    /**
     * @param array<string, mixed>|\Throwable $answer what the shop answers
     *
     * @return array{int, string}
     */
    private function check(string $env, array|\Throwable $answer): array
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $expectation = $client->expects(self::once())->method('getDecoded')->with(Route::collection(Resource::PluginCapabilities));
        $answer instanceof \Throwable ? $expectation->willThrowException($answer) : $expectation->willReturn($answer);

        $tester = new CommandTester(new CheckShopExtensionsCommand(new CapabilitiesReader($client), new ShopExtensions($env, ['sylius_cms'])));
        $status = $tester->execute([]);

        return [$status, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? ''];
    }

    private function clientException(int $status): ClientException
    {
        $response = (new MockHttpClient(new MockResponse('', ['http_code' => $status])))->request('GET', 'http://shop.test/api/v2/admin/sylius-mcp/capabilities');

        return new ClientException($response);
    }
}
