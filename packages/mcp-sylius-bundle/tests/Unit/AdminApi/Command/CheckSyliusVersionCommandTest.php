<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Command;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\CapabilitiesReader;
use Emagma\McpSyliusBundle\AdminApi\Command\CheckSyliusVersionCommand;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLineInterface;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CheckSyliusVersionCommand::class)]
final class CheckSyliusVersionCommandTest extends TestCase
{
    public function testTheSameVersionPasses(): void
    {
        [$status, $display] = $this->check('2.3', ['extensions' => [], 'sylius' => '2.3.0']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('The shop runs Sylius 2.3, as MCP_SYLIUS_VERSION says.', $display);
    }

    public function testAnotherPatchOfTheSameLinePassesNamingIt(): void
    {
        [$status, $display] = $this->check('2.2', ['sylius' => '2.2.9']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('2.2.9', $display);
        self::assertStringContainsString('another patch', $display);
    }

    public function testAPreReleaseCountsAsItsVersion(): void
    {
        [$status] = $this->check('2.3', ['sylius' => '2.3.0-RC.1']);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testAnotherMinorFailsNamingTheValueToSet(): void
    {
        [$status, $display] = $this->check('2.1', ['sylius' => '2.3.1']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('MCP_SYLIUS_VERSION is 2.1, but the shop runs Sylius 2.3.1: set MCP_SYLIUS_VERSION=2.3.1.', $display);
    }

    public function testAnUnsupportedShopVersionFails(): void
    {
        [$status, $display] = $this->check('2.3', ['sylius' => '1.13.4']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('does not support', $display);
        self::assertStringContainsString('supported lines: 1.14, 2.0, 2.1, 2.2, 2.3', $display);
    }

    public function testAPluginThatPublishesNoVersionOnlyWarns(): void
    {
        [$status, $display] = $this->check('2.3', ['extensions' => ['sylius_cms' => '1.1.6']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('WARNING', $display);
        self::assertStringContainsString('does not publish its Sylius version', $display);
    }

    public function testNoCapabilitiesEndpointFails(): void
    {
        [$status, $display] = $this->check('2.3', $this->clientException(404));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('no capabilities endpoint', $display);
    }

    public function testAnotherShopErrorFails(): void
    {
        [$status, $display] = $this->check('2.3', $this->clientException(500));

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

        $tester = new CommandTester(new CheckSyliusVersionCommand(new CapabilitiesReader($client), new SyliusVersion($env), $this->lines()));
        $status = $tester->execute([]);

        return [$status, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? ''];
    }

    private function lines(): SyliusLines
    {
        $line = $this->createStub(SyliusLineInterface::class);
        $line->method('versions')->willReturn(['1.14', '2.0', '2.1', '2.2', '2.3']);

        return new SyliusLines([$line]);
    }

    private function clientException(int $status): ClientException
    {
        $response = (new MockHttpClient(new MockResponse('', ['http_code' => $status])))->request('GET', 'http://shop.test/api/v2/admin/sylius-mcp/capabilities');

        return new ClientException($response);
    }
}
